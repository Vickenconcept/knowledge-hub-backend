<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Context Router - The intelligent decision layer
 * Determines whether to search documents, messages, or both
 */
class ContextRouter
{
    /**
     * Route query to appropriate context sources
     * Returns routing decision with confidence scores
     */
    public static function routeQuery(string $query, array $conversationHistory = []): array
    {
        $query = strtolower(trim($query));
        
        $routing = [
            'search_documents' => true,      // Default: always search docs
            'search_messages' => false,      // Enable if needed
            'attach_last_answer' => false,   // For refinements
            'route_type' => 'document',      // document | meta | refinement | hybrid
            'confidence' => 0.0,
            'reasoning' => '',
        ];
        
        // 1. Check if this is a META-QUESTION (about the conversation itself)
        if (ConversationMemoryService::isMetaQuestion($query)) {
            $routing['search_documents'] = false;
            $routing['search_messages'] = true;
            $routing['route_type'] = 'meta';
            $routing['confidence'] = 0.95;
            $routing['reasoning'] = 'User is asking about the conversation itself';
            
            Log::info('ContextRouter: META query detected', [
                'query' => $query,
                'route' => 'messages_only'
            ]);
            
            return $routing;
        }
        
        // 2. Check if this is a REFINEMENT (narrowing previous answer)
        $refinementDetection = self::detectRefinement($query, $conversationHistory);
        
        if ($refinementDetection['is_refinement']) {
            $routing['search_documents'] = true;
            $routing['search_messages'] = true;
            $routing['attach_last_answer'] = true;
            $routing['route_type'] = 'refinement';
            $routing['confidence'] = $refinementDetection['confidence'];
            $routing['reasoning'] = $refinementDetection['reasoning'];
            
            Log::info('ContextRouter: REFINEMENT query detected', [
                'query' => $query,
                'route' => 'hybrid_with_last_answer',
                'keywords' => $refinementDetection['keywords_found']
            ]);
            
            return $routing;
        }
        
        // 3. Check if query has PRONOUNS that need resolution
        $pronounCheck = self::detectPronounDependency($query, $conversationHistory);
        
        if ($pronounCheck['needs_context']) {
            $routing['search_documents'] = true;
            $routing['search_messages'] = true;
            $routing['route_type'] = 'hybrid';
            $routing['confidence'] = $pronounCheck['confidence'];
            $routing['reasoning'] = $pronounCheck['reasoning'];
            
            Log::info('ContextRouter: PRONOUN dependency detected', [
                'query' => $query,
                'route' => 'hybrid',
                'pronouns' => $pronounCheck['pronouns_found']
            ]);
            
            return $routing;
        }
        
        // 4. Check if query references PREVIOUS CONTEXT
        $contextCheck = self::detectContextReference($query);
        
        if ($contextCheck['references_context']) {
            $routing['search_documents'] = true;
            $routing['search_messages'] = true;
            $routing['route_type'] = 'hybrid';
            $routing['confidence'] = $contextCheck['confidence'];
            $routing['reasoning'] = $contextCheck['reasoning'];
            
            Log::info('ContextRouter: CONTEXT reference detected', [
                'query' => $query,
                'route' => 'hybrid',
                'keywords' => $contextCheck['keywords_found']
            ]);
            
            return $routing;
        }
        
        // 5. DEFAULT: Pure document search
        $routing['confidence'] = 0.8;
        $routing['reasoning'] = 'Standard document query, no conversation context needed';
        
        Log::info('ContextRouter: DOCUMENT query (default)', [
            'query' => $query,
            'route' => 'documents_only'
        ]);
        
        return $routing;
    }
    
    /**
     * Detect if query is a refinement of previous answer
     * Keywords: "only", "just", "specifically", "particularly", "excluding", etc.
     */
    private static function detectRefinement(string $query, array $conversationHistory): array
    {
        $refinementKeywords = [
            // Restrictive modifiers
            'only' => 0.9,
            'just' => 0.85,
            'specifically' => 0.9,
            'particularly' => 0.85,
            'solely' => 0.9,
            'exclusively' => 0.9,
            
            // Filtering
            'without' => 0.7,
            'excluding' => 0.8,
            'except' => 0.75,
            'not including' => 0.8,
            
            // Narrowing
            'narrow down' => 0.85,
            'focus on' => 0.8,
            'limit to' => 0.85,
            'filter' => 0.75,
            
            // Clarification
            'more specifically' => 0.9,
            'to be clear' => 0.8,
            'i mean' => 0.85,
        ];
        
        $foundKeywords = [];
        $maxConfidence = 0.0;
        
        foreach ($refinementKeywords as $keyword => $confidence) {
            if (stripos($query, $keyword) !== false) {
                $foundKeywords[] = $keyword;
                $maxConfidence = max($maxConfidence, $confidence);
            }
        }
        
        // Additional check: Does query have a pronoun + restrictive modifier?
        // "only his", "just the", "specifically her"
        $pronounPattern = '/\b(only|just|specifically)\s+(his|her|their|its|the)\b/i';
        if (preg_match($pronounPattern, $query)) {
            $maxConfidence = max($maxConfidence, 0.95);
            $foundKeywords[] = 'pronoun+refinement';
        }
        
        return [
            'is_refinement' => $maxConfidence > 0.7,
            'confidence' => $maxConfidence,
            'keywords_found' => $foundKeywords,
            'reasoning' => !empty($foundKeywords) 
                ? "Query contains refinement keywords: " . implode(', ', $foundKeywords)
                : "No refinement detected"
        ];
    }
    
    /**
     * Detect if query uses pronouns that need context resolution
     */
    private static function detectPronounDependency(string $query, array $conversationHistory): array
    {
        // Only flag as dependent if conversation history exists
        if (empty($conversationHistory)) {
            return [
                'needs_context' => false,
                'confidence' => 0.0,
                'pronouns_found' => [],
                'reasoning' => 'No conversation history available'
            ];
        }
        
        $pronouns = [
            'he', 'she', 'they', 'it', 'his', 'her', 'their', 'its',
            'him', 'them', 'himself', 'herself', 'themselves'
        ];
        
        $foundPronouns = [];
        foreach ($pronouns as $pronoun) {
            // Use word boundaries to avoid false matches (e.g., "the" contains "he")
            if (preg_match('/\b' . preg_quote($pronoun, '/') . '\b/i', $query)) {
                $foundPronouns[] = $pronoun;
            }
        }
        
        if (!empty($foundPronouns)) {
            return [
                'needs_context' => true,
                'confidence' => 0.85,
                'pronouns_found' => $foundPronouns,
                'reasoning' => "Query uses pronouns that may refer to entities from previous messages"
            ];
        }
        
        return [
            'needs_context' => false,
            'confidence' => 0.0,
            'pronouns_found' => [],
            'reasoning' => 'No pronouns requiring context resolution'
        ];
    }
    
    /**
     * Detect if query explicitly references previous context
     */
    private static function detectContextReference(string $query): array
    {
        $contextKeywords = [
            // Temporal references
            'previously' => 0.9,
            'earlier' => 0.85,
            'before' => 0.75,
            'last time' => 0.9,
            'just now' => 0.85,
            
            // Comparative references
            'compared to' => 0.8,
            'versus' => 0.75,
            'vs' => 0.75,
            'like you said' => 0.9,
            'as mentioned' => 0.9,
            
            // Continuation markers
            'based on' => 0.85,
            'following' => 0.75,
            'continuing' => 0.8,
            'also' => 0.6,
            'additionally' => 0.7,
        ];
        
        $foundKeywords = [];
        $maxConfidence = 0.0;
        
        foreach ($contextKeywords as $keyword => $confidence) {
            if (stripos($query, $keyword) !== false) {
                $foundKeywords[] = $keyword;
                $maxConfidence = max($maxConfidence, $confidence);
            }
        }
        
        return [
            'references_context' => $maxConfidence > 0.7,
            'confidence' => $maxConfidence,
            'keywords_found' => $foundKeywords,
            'reasoning' => !empty($foundKeywords)
                ? "Query references previous context: " . implode(', ', $foundKeywords)
                : "No context references detected"
        ];
    }
    
    /**
     * Get enhanced context based on routing decision
     */
    public static function buildEnhancedContext(
        array $routing, 
        string $conversationId, 
        array $conversationHistory = []
    ): array {
        $context = [
            'conversation_history' => [],
            'last_answer' => null,
            'message_search_results' => [],
        ];
        
        // Always include conversation context if searching messages
        if ($routing['search_messages']) {
            $context['conversation_history'] = $conversationHistory;
        }
        
        // For refinements, attach the last assistant message
        if ($routing['attach_last_answer'] && !empty($conversationHistory)) {
            $reversed = array_reverse($conversationHistory);
            foreach ($reversed as $msg) {
                if ($msg['role'] === 'assistant') {
                    $context['last_answer'] = $msg;
                    break;
                }
            }
        }
        
        return $context;
    }
}

