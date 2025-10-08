<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\Log;

class ConversationMemoryService
{
    /**
     * Detect if the query is asking about the conversation itself (meta-question)
     */
    public static function isMetaQuestion(string $query): bool
    {
        $query = strtolower(trim($query));
        
        $metaPatterns = [
            '/\b(what (did|was|were) (i|we|the last thing))\b.*\b(ask|say|discuss|talk)\b/',
            '/\b(you (said|mentioned|told|explained))\b/',
            '/\b(last (thing|question|query|message))\b.*\b(asked?|said)\b/',
            '/\b(remind me|recall|remember)\b/',
            '/\b(our (conversation|discussion|chat))\b/',
            '/\b(what was (my|the) (last|previous) (question|query|thing))\b/',
            '/\b(go back|look back)\b/',
            '/\b(you answered|your (answer|response))\b/',
        ];
        
        // Don't match if it's a cross-session query (those go to session memory)
        if (self::isSessionMemoryQuery($query)) {
            return false;
        }
        
        foreach ($metaPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                Log::info('Meta-question detected', [
                    'query' => $query,
                    'pattern' => $pattern
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect if query is asking about past sessions/conversations
     */
    public static function isSessionMemoryQuery(string $query): bool
    {
        $query = strtolower(trim($query));
        
        $sessionPatterns = [
            '/\b(last (week|month|time|session|chat))\b/',
            '/\b(previous (conversation|chat|session))\b/',
            '/\b((weeks?|months?|days?) ago)\b/',
            '/\b(in our (past|previous|earlier) (conversations?|chats?))\b/',
            '/\b(across (all|our) (conversations?|chats?))\b/',
        ];
        
        foreach ($sessionPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get recent conversation history for context
     * Returns formatted conversation exchanges
     */
    public static function getConversationContext(string $conversationId, int $limit = 10): array
    {
        $messages = Message::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
        
        $context = [];
        foreach ($messages as $msg) {
            $context[] = [
                'role' => $msg->role,
                'content' => $msg->content,
                'timestamp' => $msg->created_at->toISOString(),
            ];
        }
        
        return $context;
    }
    
    /**
     * Search past messages for relevant content
     * Simple keyword-based search (can be enhanced with embeddings later)
     */
    public static function searchConversationHistory(string $conversationId, string $query, int $limit = 5): array
    {
        $keywords = self::extractKeywords($query);
        
        $messages = Message::where('conversation_id', $conversationId)
            ->where(function($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $q->orWhere('content', 'LIKE', "%{$keyword}%");
                }
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
        
        $results = [];
        foreach ($messages as $msg) {
            $results[] = [
                'role' => $msg->role,
                'content' => $msg->content,
                'timestamp' => $msg->created_at->diffForHumans(),
                'relevance_score' => self::calculateRelevance($msg->content, $keywords),
            ];
        }
        
        // Sort by relevance
        usort($results, fn($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);
        
        return $results;
    }
    
    /**
     * Extract keywords from query for searching
     */
    private static function extractKeywords(string $query): array
    {
        // Remove common stop words
        $stopWords = ['what', 'did', 'you', 'say', 'tell', 'me', 'about', 'the', 'a', 'an', 'is', 'was', 'were', 'last', 'earlier', 'before'];
        
        $words = preg_split('/\s+/', strtolower($query));
        $keywords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) > 3 && !in_array($word, $stopWords);
        });
        
        return array_values($keywords);
    }
    
    /**
     * Calculate simple relevance score based on keyword matches
     */
    private static function calculateRelevance(string $content, array $keywords): float
    {
        $content = strtolower($content);
        $score = 0;
        
        foreach ($keywords as $keyword) {
            $score += substr_count($content, strtolower($keyword));
        }
        
        return $score;
    }
    
    /**
     * Format conversation history for LLM prompt
     */
    public static function formatConversationForPrompt(array $messages, int $maxMessages = 5): string
    {
        $recentMessages = array_slice($messages, -$maxMessages);
        
        $formatted = "RECENT CONVERSATION HISTORY:\n\n";
        
        foreach ($recentMessages as $msg) {
            $role = ucfirst($msg['role']);
            $timestamp = isset($msg['timestamp']) ? " [{$msg['timestamp']}]" : "";
            $formatted .= "{$role}{$timestamp}: {$msg['content']}\n\n";
        }
        
        $formatted .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        return $formatted;
    }
    
    /**
     * Build a meta-question response based on conversation history
     */
    public static function buildMetaResponse(string $query, array $conversationHistory): ?string
    {
        $query = strtolower($query);
        
        // Find the last user message (excluding current query)
        $lastUserMessage = null;
        $lastAssistantMessage = null;
        
        // Reverse to get most recent
        $reversed = array_reverse($conversationHistory);
        
        foreach ($reversed as $msg) {
            if ($msg['role'] === 'user' && !$lastUserMessage) {
                $lastUserMessage = $msg;
            }
            if ($msg['role'] === 'assistant' && !$lastAssistantMessage) {
                $lastAssistantMessage = $msg;
            }
            if ($lastUserMessage && $lastAssistantMessage) break;
        }
        
        // Handle different meta-question types
        if (preg_match('/what did (i|we) (ask|say).*last/i', $query)) {
            if ($lastUserMessage) {
                return "Your last question was: \"{$lastUserMessage['content']}\"";
            }
        }
        
        if (preg_match('/what (did|was) (your|the) (answer|response)/i', $query)) {
            if ($lastAssistantMessage) {
                return "My last response was:\n\n{$lastAssistantMessage['content']}";
            }
        }
        
        if (preg_match('/what (did|have) (we|i|you) (discuss|talk)/i', $query)) {
            $summary = "We've discussed:\n\n";
            $topics = [];
            
            foreach ($conversationHistory as $msg) {
                if ($msg['role'] === 'user') {
                    $topics[] = "• " . mb_substr($msg['content'], 0, 100) . (strlen($msg['content']) > 100 ? '...' : '');
                }
            }
            
            return $summary . implode("\n", array_slice($topics, -5));
        }
        
        return null; // Let RAG handle it with context
    }
}

