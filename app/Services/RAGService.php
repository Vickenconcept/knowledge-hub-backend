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

    public function assemblePrompt(
        string $query, 
        array $snippets, 
        string $responseStyle = 'comprehensive', 
        array $conversationContext = [],
        array $routing = [],
        ?array $lastAnswer = null
    ): string
    {
        $maxSnip = 8; // Reduced from 15 to 8 for faster response times (balance: quality vs speed)
        
        // Detect query intent for context-aware responses
        $intentService = new \App\Services\QueryIntentService();
        $intent = $intentService->detectIntent($query);
        
        // Check if there are no relevant documents found
        if (empty($snippets)) {
            // No context found - provide a helpful fallback response
            $buf = "You are a friendly AI assistant for a team's knowledge hub. ";
            $buf .= "The user asked: \"{$query}\"\n\n";
            $buf .= "INSTRUCTIONS:\n";
            $buf .= "- No relevant documents were found in the knowledge base for this query.\n";
            $buf .= "- Provide a helpful response explaining that you couldn't find information about their query.\n";
            $buf .= "- Suggest they try connecting more data sources or rephrasing their question.\n";
            $buf .= "- Keep it friendly and concise (2-3 sentences).\n";
            $buf .= "- Return STRICT JSON with keys: answer (string), sources (array - empty).\n";
            return $buf;
        }

        // For knowledge-based questions with context
        $buf = "You are an expert knowledge researcher and analyst for an intelligent Knowledge Hub. Your role is to deeply analyze all provided documents and synthesize comprehensive, exhaustive answers across any domain.\n\n";
        $buf .= "AVAILABLE CONTEXT (Review ALL snippets before answering):\n\n";
        $count = 0;
        $documentTypes = [];
        
        // Detect if query is an exact URL or domain (needs full context for proper matching)
        $isExactQuery = $this->isExactMatchQuery($query);
        $excerptLength = $isExactQuery ? 2000 : 1200; // Full context for exact queries, larger excerpt for others
        
        foreach ($snippets as $s) {
            if ($count >= $maxSnip) break;
            $count++;
            $docId = $s['document_id'] ?? 'unknown_id';
            $title = $s['document_title'] ?? $s['document_id'] ?? 'unknown_doc';
            $docType = $s['doc_type'] ?? 'general';
            $documentTypes[] = $docType;
            
            // Use larger excerpt to avoid missing important information
            $excerpt = mb_substr($s['text'] ?? '', 0, $excerptLength);
            $buf .= "[{$count}] Document: {$title} (Type: {$docType}, ID: {$docId})\n";
            $buf .= "Content: \"{$excerpt}\"\n";
            $buf .= "Location: chars " . ($s['char_start'] ?? 0) . "-" . ($s['char_end'] ?? 0) . "\n\n";
        }
        
        // Add context-aware guidance based on document types present
        $uniqueTypes = array_unique($documentTypes);
        $contextGuidance = $this->getContextualGuidance($uniqueTypes, $query);
        $intentGuidance = $intentService->getFormattingInstructions($intent, $uniqueTypes);
        
        // Analyze confidence scores for skills/facts
        $confidenceService = new \App\Services\ConfidenceScoreService();
        $confidenceAnalysis = $confidenceService->analyzeSnippets($snippets);
        $confidenceSummary = $confidenceService->getConfidenceSummary($confidenceAnalysis);
        
        // Add conversation context if available (reduced from 5 to 3 for speed)
        if (!empty($conversationContext)) {
            $buf .= \App\Services\ConversationMemoryService::formatConversationForPrompt($conversationContext, 3);
        }
        
        // For refinement queries, highlight the previous answer
        if (!empty($lastAnswer) && isset($routing['route_type']) && $routing['route_type'] === 'refinement') {
            $buf .= "🔍 REFINEMENT CONTEXT (User is narrowing/filtering previous response):\n\n";
            $buf .= "Previous Answer: \"" . mb_substr($lastAnswer['content'], 0, 500) . "...\"\n\n";
            $buf .= "User is now asking for a REFINED/FILTERED version of this information.\n";
            $buf .= "Pay special attention to narrowing keywords: 'only', 'just', 'specifically', 'excluding', etc.\n\n";
            $buf .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        }
        
        $buf .= "═══════════════════════════════════════════\n";
        $buf .= "User Question: \"{$query}\"\n";
        
        // Add routing context awareness
        if (isset($routing['route_type'])) {
            $buf .= "Query Type: {$routing['route_type']} (confidence: " . ($routing['confidence'] ?? 0) . ")\n";
        }
        $buf .= "Query Intent: {$intent['primary_intent']} (All: " . implode(', ', $intent['all_intents']) . ")\n";
        $buf .= "Document Types in Context: " . implode(', ', $uniqueTypes) . "\n";
        $buf .= "═══════════════════════════════════════════\n\n";
        
        // Add response style instructions
        $stylePrompt = \App\Services\ResponseStyleService::buildStylePrompt($responseStyle);
        $buf .= $stylePrompt;
        
        $buf .= $intentGuidance . "\n";
        $buf .= $contextGuidance . "\n";
        $buf .= $confidenceSummary . "\n";
        $buf .= "CRITICAL INSTRUCTIONS:\n\n";
        
        // Add special instructions for exact match queries (URLs, domains, exact terms)
        if ($isExactQuery) {
            $buf .= "⚠️ EXACT MATCH QUERY DETECTED:\n";
            $buf .= "   - The user's query appears to be a URL, domain name, or very specific term: \"{$query}\"\n";
            $buf .= "   - You MUST scan the ENTIRE CONTENT of EVERY snippet looking for this EXACT term or URL\n";
            $buf .= "   - The snippet excerpts are FULL TEXT (not truncated), so search through ALL characters carefully\n";
            $buf .= "   - Even if the term appears near the END of a snippet, you must detect and cite it\n";
            $buf .= "   - If you find the exact term, mention what document it's in and what context surrounds it\n";
            $buf .= "   - If the exact term appears NOWHERE in ANY snippet, then honestly say it's not found\n\n";
        }
        
        $buf .= "1. INTELLIGENT NAME MATCHING:\n";
        $buf .= "   - If the user asks about a specific person (e.g., 'tell me about John Smith'), check if that exact name appears in the documents\n";
        $buf .= "   - If the requested name is NOT found in any document, DO NOT hallucinate or make up information\n";
        $buf .= "   - Instead, be honest: 'I don't have information about [requested name] in the available documents'\n";
        $buf .= "   - If you find similar names, mention them: 'I don't have information about [requested name], but I found information about [similar names]'\n";
        $buf .= "   - NEVER mix up different people's information - each person's data should be kept separate\n\n";
        $buf .= "2. COMPREHENSIVE COVERAGE:\n";
        $buf .= "   - Read and analyze EVERY SINGLE snippet above (all {$count} documents)\n";
        $buf .= "   - Don't stop at the first 2-3 snippets - scan all of them for relevant information\n";
        $buf .= "   - If the question asks for 'all', 'list', 'what skills', etc., enumerate EVERYTHING found\n\n";
        $buf .= "3. INTELLIGENT SYNTHESIS:\n";
        $buf .= "   - Merge information from multiple documents when they cover the same topic or entity\n";
        $buf .= "   - Group related items logically by relevant categories (varies by domain)\n";
        $buf .= "   - Eliminate redundancy but retain unique details from each source\n";
        $buf .= "   - NEVER combine information from different people unless explicitly asked to compare them\n\n";
        $buf .= "4. ADHERENCE TO STYLE:\n";
        $buf .= "   - STRICTLY follow the Response Style format specified above\n";
        $buf .= "   - If style is 'bullet_brief', use PLAIN TEXT bullet points (• or -) with NO markdown formatting\n";
        $buf .= "   - If style is 'comprehensive', use detailed paragraphs (8-15 sentences)\n";
        $buf .= "   - If style is 'qa_friendly', be direct and conversational (2-4 sentences)\n";
        $buf .= "   - Match the detail level and structure to the chosen style\n";
        $buf .= "   - NEVER use markdown syntax (**, ##, ___, etc.) in bullet_brief style\n\n";
        $buf .= "5. OUTPUT FORMAT:\n";
        $buf .= "   - Return STRICT JSON: {\"answer\": \"your response here\", \"sources\": [\"document_id_1\", \"document_id_2\", ...]}\n";
        $buf .= "   - In the sources array, list ONLY the document IDs you actually used for your answer\n";
        $buf .= "   - Be selective: only cite documents that provided essential information for the answer\n";
        $buf .= "   - Do NOT include documents that were merely similar but not actually used\n\n";
        $buf .= "Remember: Respect the chosen response style while providing accurate, well-researched answers!\n";
        return $buf;
    }

    public function callLLM(string $prompt, int $maxTokens = 1500, ?string $orgId = null, ?int $userId = null, ?string $conversationId = null, ?string $queryText = null): array
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
            'max_tokens' => $maxTokens, // Dynamic based on response style
            'temperature' => 0.2, // Balanced for natural language and speed
            'response_format' => ['type' => 'json_object'],
            'top_p' => 0.9, // Nucleus sampling for faster generation
        ];

        $resp = Http::withToken($this->openAiKey)
            ->timeout(60) // 60 second timeout
            ->retry(3, 1000) // Retry 3 times with 1 second delay
            ->post('https://api.openai.com/v1/chat/completions', $payload);
            
        if (!$resp->successful()) {
            $statusCode = $resp->status();
            $responseBody = $resp->body();
            $responseJson = $resp->json();
            
            // Extract error message from OpenAI response
            $errorMessage = 'LLM call failed';
            if (isset($responseJson['error']['message'])) {
                $errorMessage = $responseJson['error']['message'];
            } elseif (is_string($responseBody)) {
                $errorMessage = $responseBody;
            }
            
            // Log comprehensive error details
            Log::error('RAGService LLM call failed', [
                'status_code' => $statusCode,
                'error_message' => $errorMessage,
                'response_body' => $responseBody,
                'org_id' => $orgId,
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'query_preview' => $queryText ? mb_substr($queryText, 0, 100) : null,
                'model' => $this->chatModel,
            ]);
            
            // Throw exception with detailed error message
            throw new \RuntimeException("HTTP request returned status code {$statusCode}:\n{$responseBody}");
        }

        $json = $resp->json();
        $rawText = $json['choices'][0]['message']['content'] ?? null;

        // Track cost if org_id is provided
        if ($orgId) {
            $usage = $json['usage'] ?? [];
            $tokensInput = $usage['prompt_tokens'] ?? 0;
            $tokensOutput = $usage['completion_tokens'] ?? 0;
            
            if ($tokensInput > 0 || $tokensOutput > 0) {
                \App\Services\CostTrackingService::trackChat(
                    $orgId,
                    $userId,
                    $this->chatModel,
                    $tokensInput,
                    $tokensOutput,
                    $queryText ?? mb_substr($prompt, 0, 500),
                    $conversationId
                );
            }
        }

        return [
            'answer' => $rawText,
            'raw' => $json,
        ];
    }
    
    /**
     * Provide context-aware guidance based on document types
     */
    private function getContextualGuidance(array $documentTypes, string $query): string
    {
        $guidance = "📚 DOMAIN-AWARE GUIDANCE:\n";
        
        // Check what types of documents we have
        $hasResumes = in_array('resume', $documentTypes) || in_array('cover_letter', $documentTypes);
        $hasContracts = in_array('contract', $documentTypes);
        $hasReports = in_array('report', $documentTypes);
        $hasMeetingNotes = in_array('meeting_notes', $documentTypes);
        $hasFinancial = in_array('financial', $documentTypes);
        $hasTechnical = in_array('technical_doc', $documentTypes);
        
        if ($hasResumes) {
            $guidance .= "• Resumes/CVs detected → Focus on: skills, experience, education, projects, accomplishments, URLs, links\n";
            $guidance .= "  When asked about skills, experience, or projects, enumerate ALL items found across all resumes\n";
            $guidance .= "  When asked about specific URLs or project links, search through the ENTIRE content for exact matches\n";
            $guidance .= "  Resume project sections often contain URLs at the END of sections, so scan thoroughly\n";
        }
        
        if ($hasContracts) {
            $guidance .= "• Contracts detected → Focus on: parties, terms, obligations, dates, clauses, conditions\n";
            $guidance .= "  Highlight key obligations, payment terms, and important dates\n";
        }
        
        if ($hasReports) {
            $guidance .= "• Reports detected → Focus on: findings, recommendations, data, metrics, conclusions\n";
            $guidance .= "  Synthesize key insights and quantitative data\n";
        }
        
        if ($hasMeetingNotes) {
            $guidance .= "• Meeting notes detected → Focus on: decisions, action items, attendees, topics discussed\n";
            $guidance .= "  Extract actionable items and key decisions\n";
        }
        
        if ($hasFinancial) {
            $guidance .= "• Financial documents detected → Focus on: amounts, dates, line items, totals\n";
            $guidance .= "  Be precise with numbers and dates\n";
        }
        
        if ($hasTechnical) {
            $guidance .= "• Technical documentation detected → Focus on: features, specifications, configurations, instructions, procedures\n";
            $guidance .= "  Provide specific examples or technical details when present in the documents\n";
        }
        
        // Add multi-source synthesis reminder
        if (count($documentTypes) > 1) {
            $guidance .= "• Multiple document types present → Cross-reference and synthesize insights across different sources\n";
            $guidance .= "  Look for complementary information that creates a more complete picture\n";
        }
        
        return $guidance;
    }
    
    /**
     * Detect if the query is an exact match query (URL, domain, or very specific term)
     * These queries need full context to properly match against documents
     */
    private function isExactMatchQuery(string $query): bool
    {
        // Trim whitespace
        $query = trim($query);
        
        // Check if it's a URL (starts with http:// or https://)
        if (preg_match('/^https?:\/\//i', $query)) {
            return true;
        }
        
        // Check if it's a domain (contains .com, .org, .net, etc. without http)
        if (preg_match('/\.(com|org|net|io|co|ai|dev|app|xyz|tv|info|biz|me|us|uk|ca|au|de|fr|jp|cn|in|ru|br|mx|it|es|nl|se|no|dk|fi|pl|za|ae|sa|kr|sg|hk|tw|nz|ie|pt|gr|cz|hu|ro|tr|il|th|my|ph|vn|id|pk|bd|eg|ma|ng|gh|ke|tz|ug|et|zw|ao|sn|ci|cm|mg|rw|bi|dj|km|mz|sz|zm|bw|ls|na|so|ss|sd|er|ly|tn|dz|mr|eh)/i', $query)) {
            return true;
        }
        
        // Check if it's a very short query (likely an exact term like "Gmail", "Slack", etc.)
        // Exclude common question words
        $words = explode(' ', $query);
        if (count($words) <= 2 && !in_array(strtolower($words[0] ?? ''), ['what', 'who', 'where', 'when', 'why', 'how', 'tell', 'list', 'show'])) {
            return true;
        }
        
        return false;
    }
}


