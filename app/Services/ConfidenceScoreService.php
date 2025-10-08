<?php

namespace App\Services;

class ConfidenceScoreService
{
    /**
     * Analyze snippets and generate confidence scores for mentioned skills/facts
     */
    public function analyzeSnippets(array $snippets): array
    {
        $skillMentions = [];
        $documentCoverage = [];
        
        foreach ($snippets as $snippet) {
            $text = strtolower($snippet['text'] ?? '');
            $docId = $snippet['document_id'] ?? 'unknown';
            
            // Track which documents mention which skills
            $skills = $this->extractSkillsFromText($text);
            
            foreach ($skills as $skill) {
                if (!isset($skillMentions[$skill])) {
                    $skillMentions[$skill] = [
                        'count' => 0,
                        'documents' => [],
                        'confidence' => 'low'
                    ];
                }
                
                $skillMentions[$skill]['count']++;
                if (!in_array($docId, $skillMentions[$skill]['documents'])) {
                    $skillMentions[$skill]['documents'][] = $docId;
                }
            }
        }
        
        // Calculate confidence levels
        foreach ($skillMentions as $skill => $data) {
            $docCount = count($data['documents']);
            if ($docCount >= 3) {
                $skillMentions[$skill]['confidence'] = 'strong';
            } elseif ($docCount >= 2) {
                $skillMentions[$skill]['confidence'] = 'moderate';
            } else {
                $skillMentions[$skill]['confidence'] = 'weak';
            }
        }
        
        return [
            'skill_mentions' => $skillMentions,
            'total_documents_analyzed' => count(array_unique(array_column($snippets, 'document_id'))),
            'high_confidence_skills' => array_keys(array_filter($skillMentions, fn($s) => $s['confidence'] === 'strong')),
        ];
    }
    
    /**
     * Extract skills mentioned in text
     */
    private function extractSkillsFromText(string $text): array
    {
        $skills = [];
        
        // Technology skills
        $technologies = [
            'react', 'vue', 'angular', 'next.js', 'nuxt',
            'laravel', 'django', 'flask', 'fastapi', 'express', 'node.js',
            'python', 'php', 'javascript', 'typescript', 'java', 'golang', 'rust',
            'docker', 'kubernetes', 'jenkins', 'github actions',
            'aws', 'azure', 'gcp', 'digitalocean',
            'mongodb', 'postgresql', 'mysql', 'redis', 'elasticsearch',
            'figma', 'sketch', 'adobe xd', 'photoshop', 'illustrator',
            'tailwind', 'bootstrap', 'sass', 'css',
            'git', 'ci/cd', 'agile', 'scrum',
        ];
        
        foreach ($technologies as $tech) {
            if (str_contains($text, $tech)) {
                $skills[] = $tech;
            }
        }
        
        // Skill categories
        if (str_contains($text, 'ui/ux') || str_contains($text, 'user experience')) {
            $skills[] = 'ui/ux design';
        }
        if (str_contains($text, 'frontend') || str_contains($text, 'front-end')) {
            $skills[] = 'frontend development';
        }
        if (str_contains($text, 'backend') || str_contains($text, 'back-end')) {
            $skills[] = 'backend development';
        }
        if (str_contains($text, 'full stack') || str_contains($text, 'full-stack')) {
            $skills[] = 'full-stack development';
        }
        if (str_contains($text, 'devops')) {
            $skills[] = 'devops';
        }
        
        return array_unique($skills);
    }
    
    /**
     * Generate confidence summary for prompt
     */
    public function getConfidenceSummary(array $analysis): string
    {
        if (empty($analysis['skill_mentions'])) {
            return "";
        }
        
        $summary = "\n📊 CONFIDENCE ANALYSIS:\n";
        $summary .= "Based on {$analysis['total_documents_analyzed']} documents analyzed:\n\n";
        
        // Strong evidence skills
        $strong = array_filter($analysis['skill_mentions'], fn($s) => $s['confidence'] === 'strong');
        if (!empty($strong)) {
            $summary .= "STRONG EVIDENCE (3+ documents):\n";
            foreach (array_slice($strong, 0, 10) as $skill => $data) {
                $summary .= "  • {$skill} (mentioned in " . count($data['documents']) . " documents)\n";
            }
            $summary .= "\n";
        }
        
        // Moderate evidence skills
        $moderate = array_filter($analysis['skill_mentions'], fn($s) => $s['confidence'] === 'moderate');
        if (!empty($moderate)) {
            $summary .= "MODERATE EVIDENCE (2 documents):\n";
            foreach (array_slice($moderate, 0, 8) as $skill => $data) {
                $summary .= "  • {$skill}\n";
            }
            $summary .= "\n";
        }
        
        $summary .= "Use this confidence analysis to prioritize and structure your answer.\n";
        $summary .= "Mention high-confidence skills prominently and provide more detail for them.\n\n";
        
        return $summary;
    }
}

