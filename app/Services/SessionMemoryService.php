<?php

namespace App\Services;

use App\Models\ConversationSummary;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Session Memory Service
 * Handles cross-conversation memory and long-term recall
 */
class SessionMemoryService
{
    protected string $openAiKey;
    protected string $chatModel;

    public function __construct()
    {
        $this->openAiKey = config('services.openai.key', env('OPENAI_API_KEY'));
        $this->chatModel = env('OPENAI_CHAT_MODEL', 'gpt-4o-mini');
    }

    /**
     * Check if conversation needs summarization
     * Triggers every 3 message pairs (6 messages total)
     */
    public function shouldSummarize(string $conversationId): bool
    {
        $messageCount = Message::where('conversation_id', $conversationId)->count();
        
        // Check if we've crossed a 3-turn threshold (more frequent summarization)
        $lastSummary = ConversationSummary::where('conversation_id', $conversationId)
            ->latest('turn_end')
            ->first();
        
        $lastSummarizedTurn = $lastSummary ? $lastSummary->turn_end : 0;
        $currentTurn = floor($messageCount / 2); // 2 messages = 1 turn (user + assistant)
        
        $shouldSummarize = ($currentTurn - $lastSummarizedTurn) >= 3;
        
        if ($shouldSummarize) {
            Log::info('Conversation ready for summarization', [
                'conversation_id' => $conversationId,
                'total_messages' => $messageCount,
                'last_summarized_turn' => $lastSummarizedTurn,
                'current_turn' => $currentTurn,
            ]);
        }
        
        return $shouldSummarize;
    }

    /**
     * Generate AI summary of conversation segment
     */
    public function summarizeConversation(string $conversationId): ?ConversationSummary
    {
        $conversation = Conversation::with('messages')->find($conversationId);
        
        if (!$conversation) {
            return null;
        }
        
        // Get messages since last summary
        $lastSummary = ConversationSummary::where('conversation_id', $conversationId)
            ->latest('turn_end')
            ->first();
        
        $startIndex = $lastSummary ? $lastSummary->turn_end * 2 : 0; // Convert turn to message index
        
        $messages = Message::where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->skip($startIndex)
            ->take(6) // Summarize up to 3 turns (6 messages)
            ->get();
        
        if ($messages->count() < 4) {
            // Not enough messages to summarize
            return null;
        }
        
        // Build conversation transcript for summarization
        $transcript = "";
        foreach ($messages as $msg) {
            $role = ucfirst($msg->role);
            $transcript .= "{$role}: {$msg->content}\n\n";
        }
        
        // Call LLM to generate summary
        $summaryData = $this->generateSummary($transcript);
        
        if (!$summaryData) {
            return null;
        }
        
        // Create summary record
        $summary = ConversationSummary::create([
            'conversation_id' => $conversationId,
            'user_id' => $conversation->user_id,
            'org_id' => $conversation->org_id,
            'summary' => $summaryData['summary'],
            'key_topics' => $summaryData['key_topics'] ?? [],
            'entities_mentioned' => $summaryData['entities'] ?? [],
            'decisions_made' => $summaryData['decisions'] ?? [],
            'message_count' => $messages->count(),
            'turn_start' => floor($startIndex / 2),
            'turn_end' => floor(($startIndex + $messages->count()) / 2),
            'period_start' => $messages->first()->created_at,
            'period_end' => $messages->last()->created_at,
        ]);
        
        Log::info('Conversation segment summarized', [
            'conversation_id' => $conversationId,
            'summary_id' => $summary->id,
            'messages_summarized' => $messages->count(),
        ]);
        
        return $summary;
    }

    /**
     * Generate summary using LLM
     */
    private function generateSummary(string $transcript): ?array
    {
        $prompt = "Analyze this conversation segment and provide a structured summary.\n\n";
        $prompt .= "CONVERSATION:\n{$transcript}\n\n";
        $prompt .= "INSTRUCTIONS:\n";
        $prompt .= "Provide a JSON response with:\n";
        $prompt .= "1. summary: A 2-3 sentence overview of what was discussed\n";
        $prompt .= "2. key_topics: Array of main topics/subjects (e.g., ['skills', 'experience', 'projects'])\n";
        $prompt .= "3. entities: Array of people, companies, or products mentioned (e.g., ['William Victor', 'Laravel', 'FluenceGrid'])\n";
        $prompt .= "4. decisions: Array of conclusions, action items, or key facts established\n\n";
        $prompt .= "Return STRICT JSON format.";
        
        try {
            $response = Http::withToken($this->openAiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->chatModel,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a conversation analyst that creates structured summaries.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 500,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                ]);
            
            if (!$response->successful()) {
                Log::error('Summary generation failed', ['status' => $response->status()]);
                return null;
            }
            
            $json = $response->json();
            $content = $json['choices'][0]['message']['content'] ?? null;
            
            return json_decode($content, true);
        } catch (\Exception $e) {
            Log::error('Summary generation exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Search across all past conversations for a user
     */
    public function searchUserHistory(int $userId, string $query, int $limit = 5): array
    {
        $keywords = $this->extractKeywords($query);
        
        // Search summaries
        $summaries = ConversationSummary::where('user_id', $userId)
            ->where(function($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $q->orWhere('summary', 'LIKE', "%{$keyword}%");
                }
            })
            ->latest('period_end')
            ->limit($limit)
            ->with('conversation:id,title')
            ->get();
        
        $results = [];
        foreach ($summaries as $summary) {
            $results[] = [
                'conversation_id' => $summary->conversation_id,
                'conversation_title' => $summary->conversation->title ?? 'Untitled',
                'summary' => $summary->summary,
                'key_topics' => $summary->key_topics,
                'when' => $summary->period_end->diffForHumans(),
                'date' => $summary->period_end->format('M d, Y'),
            ];
        }
        
        return $results;
    }

    /**
     * Extract keywords from query for searching
     */
    private function extractKeywords(string $query): array
    {
        $stopWords = ['what', 'did', 'we', 'discuss', 'talk', 'about', 'the', 'a', 'an', 'last', 'week', 'month'];
        $words = preg_split('/\s+/', strtolower($query));
        $keywords = array_filter($words, fn($w) => strlen($w) > 3 && !in_array($w, $stopWords));
        
        return array_values($keywords);
    }

    /**
     * Format session memory search results for display
     */
    public function formatSessionResults(array $results, string $query): string
    {
        if (empty($results)) {
            return "I don't have any memory of discussing that topic in our past conversations.";
        }
        
        $response = "I found " . count($results) . " past conversation(s) about this:\n\n";
        
        foreach ($results as $result) {
            $response .= "📅 {$result['when']} ({$result['date']})\n";
            $response .= "   Conversation: {$result['conversation_title']}\n";
            $response .= "   Summary: {$result['summary']}\n";
            
            if (!empty($result['key_topics'])) {
                $response .= "   Topics: " . implode(', ', $result['key_topics']) . "\n";
            }
            
            $response .= "\n";
        }
        
        return trim($response);
    }
}

