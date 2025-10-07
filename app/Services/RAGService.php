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
        $maxSnip = 6;
        
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
        $buf = "You are an intelligent AI assistant helping users find information from their team's knowledge base. Be conversational and helpful.\n\n";
        $buf .= "Context snippets from relevant documents:\n";
        $count = 0;
        foreach ($snippets as $s) {
            if ($count >= $maxSnip) break;
            $count++;
            $title = $s['document_id'] ?? 'unknown_doc';
            $excerpt = mb_substr($s['text'] ?? '', 0, 800);
            $buf .= "[{$count}] Document: {$title} | excerpt: \"{$excerpt}\" | chars: " . ($s['char_start'] ?? 0) . "-" . ($s['char_end'] ?? 0) . "\n\n";
        }
        $buf .= "User question: \"{$query}\"\n\n";
        $buf .= "INSTRUCTIONS:\n";
        $buf .= "- Provide a clear, conversational answer (3-6 sentences) using the context.\n";
        $buf .= "- Be helpful and natural - don't sound robotic.\n";
        $buf .= "- If the context contains relevant info, USE IT. Don't say you don't know.\n";
        $buf .= "- If the answer truly isn't in the context, say so politely and suggest what they could search for.\n";
        $buf .= "- Return STRICT JSON with keys: answer (string), sources (array of {id:number, document_id:string, char_start:number, char_end:number}).\n";
        $buf .= "- List the snippet IDs you actually used in your answer.\n";
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
                ['role' => 'system', 'content' => 'You are a helpful assistant that must cite source excerpts provided.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 512,
            'temperature' => 0.0,
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


