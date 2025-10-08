<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RAGService
{
    protected string $openAiKey;
    protected string $chatModel;

    public function __construct()
    {
        $this->openAiKey = config('services.openai.key', env('OPENAI_API_KEY'));
        $this->chatModel = env('OPENAI_CHAT_MODEL', 'gpt-4o-mini');
    }

    public function assemblePrompt(string $query, array $snippets): string
    {
        $maxSnip = 15; // Increased from 6 to 15 for more comprehensive context
        
        // Check if this is a conversational greeting or casual question
        $greetings = ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening'];
        $isGreeting = false;
        foreach ($greetings as $greeting) {
            if (stripos($query, $greeting) !== false && strlen($query) < 50) {
                $isGreeting = true;
                break;
            }
        }

        if ($isGreeting || empty($snippets)) {
            // For greetings or when no context is found, be conversational
            $buf = "You are a friendly AI assistant for a team's knowledge hub. ";
            $buf .= "The user just said: \"{$query}\"\n\n";
            $buf .= "INSTRUCTIONS:\n";
            $buf .= "- If it's a greeting (hello, hi, etc.), respond warmly and briefly explain what you can help with.\n";
            $buf .= "- If it's a casual question not requiring document search, answer conversationally.\n";
            $buf .= "- Keep it friendly and concise (2-3 sentences).\n";
            $buf .= "- Return STRICT JSON with keys: answer (string), sources (array - can be empty for greetings).\n";
            return $buf;
        }

        // For knowledge-based questions with context
        $buf = "You are an expert knowledge researcher and analyst. Your role is to deeply analyze all provided documents and synthesize comprehensive, exhaustive answers.\n\n";
        $buf .= "AVAILABLE CONTEXT (Review ALL snippets before answering):\n\n";
        $count = 0;
        foreach ($snippets as $s) {
            if ($count >= $maxSnip) break;
            $count++;
            $title = $s['document_id'] ?? 'unknown_doc';
            $excerpt = mb_substr($s['text'] ?? '', 0, 1200); // Increased from 800 to 1200 characters
            $buf .= "[{$count}] Document: {$title}\n";
            $buf .= "Content: \"{$excerpt}\"\n";
            $buf .= "Location: chars " . ($s['char_start'] ?? 0) . "-" . ($s['char_end'] ?? 0) . "\n\n";
        }
        $buf .= "═══════════════════════════════════════════\n";
        $buf .= "User Question: \"{$query}\"\n";
        $buf .= "═══════════════════════════════════════════\n\n";
        $buf .= "CRITICAL INSTRUCTIONS:\n\n";
        $buf .= "1. COMPREHENSIVE COVERAGE:\n";
        $buf .= "   - Read and analyze EVERY SINGLE snippet above (all {$count} documents)\n";
        $buf .= "   - Don't stop at the first 2-3 snippets - scan all of them for relevant information\n";
        $buf .= "   - If the question asks for 'all', 'list', 'what skills', etc., enumerate EVERYTHING found\n\n";
        $buf .= "2. DEPTH & DETAIL:\n";
        $buf .= "   - Provide 8-15 sentences for detailed questions (not 2-3)\n";
        $buf .= "   - Include specific technologies, tools, numbers, dates, project names\n";
        $buf .= "   - Mention accomplishments, certifications, and unique qualifications\n\n";
        $buf .= "3. INTELLIGENT SYNTHESIS:\n";
        $buf .= "   - Merge information from multiple documents (e.g., UI/UX resume + Developer resume)\n";
        $buf .= "   - Group related items logically:\n";
        $buf .= "     * For skills: Frontend, Backend, UI/UX, DevOps, Soft Skills\n";
        $buf .= "     * For experience: Chronological order with details\n";
        $buf .= "     * For projects: Key achievements and technologies used\n";
        $buf .= "   - Eliminate redundancy but retain unique details from each source\n\n";
        $buf .= "4. STRUCTURED OUTPUT:\n";
        $buf .= "   - Use clear topic sentences and logical flow\n";
        $buf .= "   - When listing items, use natural language (not bullet points in the answer text)\n";
        $buf .= "   - Example: 'His technical skills include React.js, Vue.js, and Next.js for frontend development; Laravel, Django, and Node.js for backend; as well as UI/UX design expertise in Figma and user research.'\n\n";
        $buf .= "5. COMPLETENESS CHECK:\n";
        $buf .= "   - Before finalizing your answer, verify you've addressed the question using information from ALL relevant snippets\n";
        $buf .= "   - If you only used 2-3 snippets but there are 10+, you're probably missing important information!\n\n";
        $buf .= "6. OUTPUT FORMAT:\n";
        $buf .= "   - Return STRICT JSON: {\"answer\": \"your detailed response here\", \"sources\": [{\"id\":1, \"document_id\":\"...\", \"char_start\":0, \"char_end\":100}, ...]}\n";
        $buf .= "   - In the sources array, list ALL snippet IDs you actually referenced (not just the first one)\n\n";
        $buf .= "Remember: This is a knowledge hub - users expect comprehensive, researched answers, not brief summaries. Dig deep!\n";
        return $buf;
    }

    public function callLLM(string $prompt): array
    {
        if (empty($this->openAiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY not configured for RAGService.');
        }

        $payload = [
            'model' => $this->chatModel,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a comprehensive knowledge assistant that provides detailed, thorough answers by synthesizing information from multiple sources.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 1500, // Increased from 512 to 1500 for more detailed responses
            'temperature' => 0.1, // Slightly increased from 0.0 for more natural language
            'response_format' => ['type' => 'json_object'],
        ];

        $resp = Http::withToken($this->openAiKey)
            ->timeout(60) // 60 second timeout
            ->retry(3, 1000) // Retry 3 times with 1 second delay
            ->post('https://api.openai.com/v1/chat/completions', $payload);
            
        if (!$resp->successful()) {
            Log::error('RAGService LLM call failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            throw new \RuntimeException('LLM call failed.');
        }

        $json = $resp->json();
        $rawText = $json['choices'][0]['message']['content'] ?? null;

        return [
            'answer' => $rawText,
            'raw' => $json,
        ];
    }
}


