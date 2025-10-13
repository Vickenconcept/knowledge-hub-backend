<?php

namespace App\Services;

class ChunkingService
{
    public function splitWithOverlap(string $text, int $targetChars = 2000, int $overlapChars = 200): array
    {
        $text = trim($text);
        if ($text === '') return [];

        // Try to detect structured content (questions, lists, sections)
        $hasNumberedList = preg_match('/^\s*\d+[\.\)]/m', $text);
        $hasBulletPoints = preg_match('/^\s*[-\*•]/m', $text);
        $hasQuestions = preg_match('/\?[\s]*$/m', $text);
        
        // For structured content, split by logical units
        if ($hasNumberedList || $hasBulletPoints || $hasQuestions) {
            return $this->splitByStructure($text, $targetChars, $overlapChars);
        }

        // Otherwise, use sentence-based splitting
        $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $text) ?: [$text];

        $chunks = [];
        $current = '';
        $currentStart = 0;
        $cursor = 0;

        foreach ($sentences as $sentence) {
            $len = mb_strlen($sentence) + 1; // include space/newline
            if ($current === '') {
                $currentStart = $cursor;
            }
            if (mb_strlen($current) + $len <= $targetChars) {
                $current .= ($current === '' ? '' : ' ') . $sentence;
            } else {
                $chunks[] = [
                    'text' => $current,
                    'char_start' => $currentStart,
                    'char_end' => $currentStart + mb_strlen($current),
                ];
                // Create overlap seed from end of current
                $overlapSeed = mb_substr($current, max(0, mb_strlen($current) - $overlapChars));
                $current = $overlapSeed . ' ' . $sentence;
                $currentStart = max(0, $cursor - $overlapChars);
            }
            $cursor += $len;
        }

        if ($current !== '') {
            $chunks[] = [
                'text' => $current,
                'char_start' => $currentStart,
                'char_end' => $currentStart + mb_strlen($current),
            ];
        }

        return $chunks;
    }
    
    /**
     * Split text by structure (numbered lists, bullet points, questions)
     * Keeps related items together
     */
    private function splitByStructure(string $text, int $targetChars, int $overlapChars): array
    {
        $chunks = [];
        $lines = explode("\n", $text);
        $current = '';
        $currentStart = 0;
        $cursor = 0;
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            $len = mb_strlen($line) + 1; // include newline
            
            // Check if this is a new section/question/item
            $isNewSection = preg_match('/^\s*\d+[\.\)]\s+/', $line) ||  // Numbered: "1. ", "1) "
                           preg_match('/^\s*[-\*•]\s+/', $line) ||       // Bullet: "- ", "* ", "• "
                           preg_match('/\?\s*$/', $line);                // Question ending with ?
            
            if ($current === '') {
                $currentStart = $cursor;
                $current = $line;
            } elseif ($isNewSection && mb_strlen($current) > $targetChars * 0.5) {
                // If we hit a new section and current chunk is decent size, create chunk
                $chunks[] = [
                    'text' => trim($current),
                    'char_start' => $currentStart,
                    'char_end' => $currentStart + mb_strlen($current),
                ];
                
                // Start new chunk with small overlap
                $overlapSeed = mb_substr($current, max(0, mb_strlen($current) - $overlapChars));
                $current = $overlapSeed . "\n" . $line;
                $currentStart = max(0, $cursor - $overlapChars);
            } elseif (mb_strlen($current) + $len > $targetChars * 1.5) {
                // If chunk is getting too large, force split
                $chunks[] = [
                    'text' => trim($current),
                    'char_start' => $currentStart,
                    'char_end' => $currentStart + mb_strlen($current),
                ];
                $current = $line;
                $currentStart = $cursor;
            } else {
                // Add line to current chunk
                $current .= "\n" . $line;
            }
            
            $cursor += $len;
        }
        
        if ($current !== '') {
            $chunks[] = [
                'text' => trim($current),
                'char_start' => $currentStart,
                'char_end' => $currentStart + mb_strlen($current),
            ];
        }
        
        return $chunks;
    }
}


