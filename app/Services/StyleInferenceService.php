<?php

namespace App\Services;

class StyleInferenceService
{
    /**
     * Infer response style from user query text
     * Analyzes natural language patterns to determine the best response format
     */
    public static function inferStyleFromQuery(string $query): ?string
    {
        $query = strtolower(trim($query));
        
        // Pattern matching for different styles
        $patterns = [
            'bullet_brief' => [
                '/\b(list|enumerate|bullet|points?|briefly|quick)\b/',
                '/\bwhat are (all|the)\b/',
                '/\bshow me (all|the)\b/',
                '/\bgive me a list\b/',
            ],
            
            'qa_friendly' => [
                '/^(does|is|can|has|have|do|will|would|should)\b/',
                '/\?$/',
                '/\b(yes or no|true or false)\b/',
                '/\b(simply|quick question|just wondering)\b/',
            ],
            
            'executive_summary' => [
                '/\b(overview|summary|tldr|tl;dr|gist|key points?|main points?)\b/',
                '/\b(summarize|sum up|give me the)\b/',
                '/\b(high-level|executive|at a glance)\b/',
                '/\b(what.s the bottom line|in a nutshell)\b/',
            ],
            
            'structured_profile' => [
                '/\b(profile|resume|cv|background|biography|bio)\b/',
                '/\b(tell me about|who is|introduce)\b/',
                '/\b(complete|full|detailed) (profile|overview)\b/',
                '/\b(skills? (and|,) experience)\b/',
            ],
            
            'comprehensive' => [
                '/\b(explain|describe|elaborate|detail|comprehensive|thorough)\b/',
                '/\b(everything|all information|complete|full details?)\b/',
                '/\b(in depth|deeply|extensively)\b/',
                '/\bhow (does|do|did|can)\b.*\bwork\b/',
            ],
            
            'summary_report' => [
                '/\b(report|findings?|analysis|insights?)\b/',
                '/\b(what did|what does|what happened)\b/',
                '/\b(compare|comparison|versus|vs\.?|differences?)\b/',
            ],
        ];
        
        // Score each style based on pattern matches
        $scores = [];
        foreach ($patterns as $style => $regexList) {
            $score = 0;
            foreach ($regexList as $regex) {
                if (preg_match($regex, $query)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[$style] = $score;
            }
        }
        
        // Return the style with highest score, or null if no match
        if (empty($scores)) {
            return null;
        }
        
        arsort($scores);
        return array_key_first($scores);
    }
    
    /**
     * Determine if query complexity warrants adaptive depth adjustment
     */
    public static function assessQueryComplexity(string $query): string
    {
        $wordCount = str_word_count($query);
        $hasMultipleClauses = substr_count($query, ',') > 2 || substr_count($query, 'and') > 2;
        $hasComplexPhrases = preg_match('/\b(compare|analyze|evaluate|assess|relationship|impact)\b/i', $query);
        
        // Complex queries
        if ($wordCount > 15 || $hasMultipleClauses || $hasComplexPhrases) {
            return 'high'; // Use comprehensive style
        }
        
        // Medium queries
        if ($wordCount > 7) {
            return 'medium'; // Use current style or default
        }
        
        // Simple queries
        return 'low'; // Consider qa_friendly or bullet_brief
    }
    
    /**
     * Get recommended style based on query analysis
     * Combines inference and complexity assessment
     */
    public static function getRecommendedStyle(string $query, ?string $currentConversationStyle = null): string
    {
        // First, try to infer from natural language patterns
        $inferredStyle = self::inferStyleFromQuery($query);
        
        if ($inferredStyle) {
            \Log::info('Style inferred from query', [
                'query' => $query,
                'inferred_style' => $inferredStyle
            ]);
            return $inferredStyle;
        }
        
        // If no clear inference, use adaptive depth logic
        $complexity = self::assessQueryComplexity($query);
        
        if ($complexity === 'high') {
            return 'comprehensive';
        } elseif ($complexity === 'low') {
            return 'qa_friendly';
        }
        
        // Default: use conversation's current style or comprehensive
        return $currentConversationStyle ?? 'comprehensive';
    }
    
    /**
     * Check if user explicitly requested a style change in their query
     * e.g., "give me a brief summary" or "list this in bullet points"
     */
    public static function detectExplicitStyleRequest(string $query): ?array
    {
        $query = strtolower($query);
        
        $explicitRequests = [
            'bullet' => 'bullet_brief',
            'brief' => 'bullet_brief',
            'list' => 'bullet_brief',
            'summarize' => 'executive_summary',
            'summary' => 'executive_summary',
            'overview' => 'executive_summary',
            'quick' => 'qa_friendly',
            'simple' => 'qa_friendly',
            'detail' => 'comprehensive',
            'thorough' => 'comprehensive',
            'complete' => 'comprehensive',
            'profile' => 'structured_profile',
        ];
        
        foreach ($explicitRequests as $keyword => $style) {
            if (stripos($query, $keyword) !== false) {
                return [
                    'detected' => true,
                    'keyword' => $keyword,
                    'style' => $style,
                    'confidence' => 'high'
                ];
            }
        }
        
        return null;
    }
}

