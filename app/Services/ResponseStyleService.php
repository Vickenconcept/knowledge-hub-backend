<?php

namespace App\Services;

class ResponseStyleService
{
    /**
     * Get all available response styles
     */
    public static function getAvailableStyles(): array
    {
        return [
            'comprehensive' => [
                'name' => 'Comprehensive',
                'description' => 'Detailed, thorough answers with full context (8-15 sentences)',
                'detail_level' => 'high',
                'structure' => 'paragraph',
                'max_tokens' => 1500,
            ],
            'structured_profile' => [
                'name' => 'Structured Profile',
                'description' => 'Organized by sections: Skills, Experience, Education, etc.',
                'detail_level' => 'high',
                'structure' => 'sectioned',
                'max_tokens' => 1500,
            ],
            'summary_report' => [
                'name' => 'Summary Report',
                'description' => 'Concise summaries with key insights (4-6 sentences)',
                'detail_level' => 'medium',
                'structure' => 'paragraph',
                'max_tokens' => 800,
            ],
            'qa_friendly' => [
                'name' => 'Q&A Friendly',
                'description' => 'Direct, conversational answers (2-4 sentences)',
                'detail_level' => 'medium',
                'structure' => 'conversational',
                'max_tokens' => 500,
            ],
            'bullet_brief' => [
                'name' => 'Bullet Points',
                'description' => 'Concise bullet-point lists',
                'detail_level' => 'low',
                'structure' => 'bullets',
                'max_tokens' => 600,
            ],
            'executive_summary' => [
                'name' => 'Executive Summary',
                'description' => 'High-level overview for decision makers',
                'detail_level' => 'medium',
                'structure' => 'executive',
                'max_tokens' => 700,
            ],
        ];
    }
    
    /**
     * Get style-specific instructions for LLM
     */
    public static function getStyleInstructions(string $style): array
    {
        $styles = self::getAvailableStyles();
        $config = $styles[$style] ?? $styles['comprehensive'];
        
        $instructions = match($style) {
            'comprehensive' => [
                'format' => 'Provide a comprehensive, detailed answer in paragraph form (8-15 sentences).',
                'structure' => 'Use clear topic sentences. Group related information logically.',
                'emphasis' => 'Include specific details, technologies, dates, and accomplishments.',
            ],
            
            'structured_profile' => [
                'format' => 'Structure your answer into clear sections with headings (use natural language, not markdown).',
                'structure' => 'Example: "PROFESSIONAL SUMMARY: ... TECHNICAL SKILLS: Frontend includes React, Vue... EXPERIENCE: Most recently at..."',
                'emphasis' => 'Organize by logical categories. Use parallel structure for each section.',
            ],
            
            'summary_report' => [
                'format' => 'Provide a concise executive summary (4-6 sentences).',
                'structure' => 'Lead with key finding, support with 2-3 main points, conclude with implications.',
                'emphasis' => 'Focus on actionable insights and high-level takeaways.',
            ],
            
            'qa_friendly' => [
                'format' => 'Answer directly and conversationally (2-4 sentences).',
                'structure' => 'Get straight to the point. Be friendly and clear.',
                'emphasis' => 'Prioritize clarity over comprehensiveness.',
            ],
            
            'bullet_brief' => [
                'format' => 'Provide your answer as a bulleted list using natural language (e.g., "Key skills include: React for frontend, Laravel for backend, Docker for containerization").',
                'structure' => 'Group bullets by category if applicable.',
                'emphasis' => 'Be concise. Each point should be self-contained.',
            ],
            
            'executive_summary' => [
                'format' => 'Write an executive summary for senior decision makers (5-7 sentences).',
                'structure' => 'Start with the "so what", then key facts, end with recommendation/implication.',
                'emphasis' => 'Focus on business impact, outcomes, and strategic relevance.',
            ],
            
            default => [
                'format' => 'Provide a comprehensive, well-organized answer.',
                'structure' => 'Use clear paragraphs with logical flow.',
                'emphasis' => 'Be thorough and helpful.',
            ],
        };
        
        return [
            'config' => $config,
            'instructions' => $instructions,
        ];
    }
    
    /**
     * Build style-specific prompt section
     */
    public static function buildStylePrompt(string $style): string
    {
        $styleData = self::getStyleInstructions($style);
        $instructions = $styleData['instructions'];
        $config = $styleData['config'];
        
        $prompt = "🎨 RESPONSE STYLE: {$config['name']}\n";
        $prompt .= "   {$config['description']}\n\n";
        $prompt .= "FORMATTING REQUIREMENTS:\n";
        $prompt .= "• Format: {$instructions['format']}\n";
        $prompt .= "• Structure: {$instructions['structure']}\n";
        $prompt .= "• Emphasis: {$instructions['emphasis']}\n\n";
        
        return $prompt;
    }
}

