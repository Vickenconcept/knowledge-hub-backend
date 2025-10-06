<?php

namespace App\Services;

class ChunkingService
{
    public function splitWithOverlap(string $text, int $targetChars = 2000, int $overlapChars = 200): array
    {
        $text = trim($text);
        if ($text === '') return [];

        // Split into sentences (very simple heuristic)
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
}


