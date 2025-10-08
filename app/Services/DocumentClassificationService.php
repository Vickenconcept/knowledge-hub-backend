<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DocumentClassificationService
{
    /**
     * Classify document based on content, filename, and metadata
     */
    public function classifyDocument(string $text, string $filename, string $mimeType): array
    {
        $textLower = strtolower($text);
        $filenameLower = strtolower($filename);
        
        // Auto-detect document type
        $docType = $this->detectDocumentType($textLower, $filenameLower, $mimeType);
        
        // Extract keywords/tags
        $tags = $this->extractTags($textLower, $filenameLower, $docType);
        
        // Extract metadata (entities, dates, etc.)
        $metadata = $this->extractMetadata($text, $docType);
        
        return [
            'doc_type' => $docType,
            'tags' => $tags,
            'metadata' => $metadata,
        ];
    }
    
    /**
     * Detect document type using heuristics
     */
    private function detectDocumentType(string $text, string $filename, string $mimeType): string
    {
        // Check filename patterns first
        if (preg_match('/(resume|cv|curriculum)/i', $filename)) {
            return 'resume';
        }
        if (preg_match('/(cover.*letter|application)/i', $filename)) {
            return 'cover_letter';
        }
        if (preg_match('/(contract|agreement|terms)/i', $filename)) {
            return 'contract';
        }
        if (preg_match('/(report|analysis|summary)/i', $filename)) {
            return 'report';
        }
        if (preg_match('/(invoice|receipt|bill)/i', $filename)) {
            return 'financial';
        }
        if (preg_match('/(proposal|pitch|deck)/i', $filename)) {
            return 'proposal';
        }
        if (preg_match('/(meeting|notes|minutes)/i', $filename)) {
            return 'meeting_notes';
        }
        
        // Content-based detection
        $resumeKeywords = ['experience', 'education', 'skills', 'professional summary', 'work history'];
        $resumeCount = 0;
        foreach ($resumeKeywords as $kw) {
            if (str_contains($text, $kw)) $resumeCount++;
        }
        if ($resumeCount >= 3) {
            return 'resume';
        }
        
        // Contract patterns
        if (str_contains($text, 'whereas') && str_contains($text, 'hereinafter') || 
            str_contains($text, 'terms and conditions')) {
            return 'contract';
        }
        
        // Financial document
        if (str_contains($text, 'invoice') || str_contains($text, 'total amount') || 
            str_contains($text, 'payment due')) {
            return 'financial';
        }
        
        // Presentation
        if (str_contains($mimeType, 'presentation') || str_contains($filename, '.ppt')) {
            return 'presentation';
        }
        
        // Spreadsheet
        if (str_contains($mimeType, 'spreadsheet') || str_contains($filename, '.xls')) {
            return 'spreadsheet';
        }
        
        // Code/technical documentation
        if (str_contains($text, 'function') && str_contains($text, 'class') || 
            str_contains($filename, 'readme') || str_contains($filename, 'documentation')) {
            return 'technical_doc';
        }
        
        // Default based on MIME type
        if (str_contains($mimeType, 'text/')) {
            return 'text_document';
        }
        
        return 'general_document';
    }
    
    /**
     * Extract relevant tags from document
     * Uses generic keyword extraction - works for any domain
     */
    private function extractTags(string $text, string $filename, string $docType): array
    {
        $tags = [];
        
        // Add doc type as a tag
        $tags[] = $docType;
        
        // Generic keyword extraction using frequency analysis
        // Extract important capitalized words and technical terms
        $words = str_word_count($text, 1);
        $wordFreq = array_count_values(array_map('strtolower', $words));
        
        // Get common words (appeared 2+ times, length > 3)
        $commonWords = array_filter($wordFreq, fn($count, $word) => 
            $count >= 2 && 
            strlen($word) > 3 && 
            !in_array($word, ['this', 'that', 'with', 'from', 'have', 'been', 'were', 'will', 'your', 'their', 'there', 'where'])
        , ARRAY_FILTER_USE_BOTH);
        
        // Sort by frequency and take top keywords
        arsort($commonWords);
        $topKeywords = array_slice(array_keys($commonWords), 0, 15);
        
        // Also extract any @ mentions, # tags, or ALL CAPS terms (likely important)
        preg_match_all('/\b[A-Z]{2,}\b/', $text, $capsTerms);
        preg_match_all('/#\w+/', $text, $hashtags);
        
        // Combine all tags
        $tags = array_merge(
            $tags,
            $topKeywords,
            array_slice(array_map('strtolower', $capsTerms[0] ?? []), 0, 5),
            array_map(fn($tag) => ltrim($tag, '#'), $hashtags[0] ?? [])
        );
        
        // Remove duplicates and common stopwords
        $stopwords = ['document', 'file', 'page', 'content', 'text', 'info', 'information', 'data'];
        $tags = array_filter($tags, fn($tag) => !in_array(strtolower($tag), $stopwords));
        
        // Limit to most relevant
        return array_values(array_unique(array_slice($tags, 0, 20)));
    }
    
    /**
     * Extract metadata (entities, dates, etc.)
     */
    private function extractMetadata(string $text, string $docType): array
    {
        $metadata = [];
        
        // Extract emails
        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $emails);
        if (!empty($emails[0])) {
            $metadata['emails'] = array_unique($emails[0]);
        }
        
        // Extract phone numbers (various formats)
        preg_match_all('/[\+]?[(]?[0-9]{1,4}[)]?[-\s\.]?[(]?[0-9]{1,4}[)]?[-\s\.]?[0-9]{1,4}[-\s\.]?[0-9]{1,9}/', $text, $phones);
        if (!empty($phones[0])) {
            $metadata['phones'] = array_unique(array_slice($phones[0], 0, 3));
        }
        
        // Extract years (for experience, dates)
        preg_match_all('/\b(19|20)\d{2}\b/', $text, $years);
        if (!empty($years[0])) {
            $metadata['years_mentioned'] = array_unique($years[0]);
        }
        
        // Extract URLs
        preg_match_all('/https?:\/\/[^\s]+/', $text, $urls);
        if (!empty($urls[0])) {
            $metadata['urls'] = array_unique(array_slice($urls[0], 0, 5));
        }
        
        // Word count
        $metadata['word_count'] = str_word_count($text);
        
        // Estimated reading time (200 words per minute)
        $metadata['reading_time_minutes'] = max(1, round($metadata['word_count'] / 200));
        
        // Language detection (simple heuristic)
        $metadata['language'] = $this->detectLanguage($text);
        
        return $metadata;
    }
    
    /**
     * Simple language detection
     */
    private function detectLanguage(string $text): string
    {
        // Very basic heuristic - could be enhanced with actual language detection library
        $sample = mb_substr($text, 0, 500);
        
        // Check for common English words
        $englishWords = ['the', 'and', 'is', 'in', 'to', 'of', 'for'];
        $englishCount = 0;
        foreach ($englishWords as $word) {
            if (str_contains(strtolower($sample), $word)) {
                $englishCount++;
            }
        }
        
        return $englishCount >= 4 ? 'en' : 'unknown';
    }
}

