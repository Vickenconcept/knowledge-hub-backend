<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Chunk;
use Illuminate\Support\Facades\Log;

/**
 * Intelligent Name Matching Service
 * Handles name matching and prevents hallucination in domain-agnostic RAG systems
 */
class NameMatchingService
{
    /**
     * Check if the requested name matches any names in the available documents
     * CRITICAL: Only searches documents the user has access to
     */
    public static function findNameMatches(string $requestedName, array $snippets, string $orgId, string $userId = null): array
    {
        $requestedName = trim($requestedName);
        $requestedNameLower = strtolower($requestedName);
        
        Log::info('🔍 Name matching analysis', [
            'requested_name' => $requestedName,
            'snippets_count' => count($snippets),
            'user_id' => $userId,
            'org_id' => $orgId
        ]);
        
        // CRITICAL SECURITY: Filter snippets by user permissions
        $filteredSnippets = self::filterSnippetsByUserPermissions($snippets, $orgId, $userId);
        
        Log::info('🔒 Security filtering applied', [
            'original_snippets' => count($snippets),
            'filtered_snippets' => count($filteredSnippets),
            'user_id' => $userId
        ]);
        
        $foundNames = [];
        $exactMatches = [];
        $partialMatches = [];
        $noMatches = true;
        
        foreach ($filteredSnippets as $snippet) {
            $text = $snippet['text'] ?? '';
            $documentTitle = $snippet['document_title'] ?? '';
            
            // Extract all potential names from the text
            $extractedNames = self::extractNamesFromText($text, $documentTitle);
            
            foreach ($extractedNames as $extractedName) {
                $extractedNameLower = strtolower($extractedName);
                
                // Check for exact match
                if ($extractedNameLower === $requestedNameLower) {
                    $exactMatches[] = $extractedName;
                    $noMatches = false;
                }
                // Check for partial match (first name or last name)
                elseif (self::hasPartialMatch($requestedNameLower, $extractedNameLower)) {
                    $partialMatches[] = $extractedName;
                    $noMatches = false;
                }
                
                $foundNames[] = $extractedName;
            }
        }
        
        $result = [
            'requested_name' => $requestedName,
            'exact_matches' => array_unique($exactMatches),
            'partial_matches' => array_unique($partialMatches),
            'all_found_names' => array_unique($foundNames),
            'has_exact_match' => !empty($exactMatches),
            'has_partial_match' => !empty($partialMatches),
            'no_matches' => $noMatches,
            'confidence' => self::calculateConfidence($exactMatches, $partialMatches, $requestedName)
        ];
        
        Log::info('🎯 Name matching result', $result);
        
        return $result;
    }
    
    /**
     * Extract names from text using multiple patterns
     */
    private static function extractNamesFromText(string $text, string $documentTitle): array
    {
        $names = [];
        
        // Pattern 1: All caps names (e.g., "WILLIAM VICTOR")
        if (preg_match_all('/\b([A-Z]{2,}\s+[A-Z]{2,}(?:\s+[A-Z]{2,})?)\b/', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $names[] = ucwords(strtolower($match));
            }
        }
        
        // Pattern 2: Proper case names (e.g., "William Victor")
        if (preg_match_all('/\b([A-Z][a-z]+\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $names[] = $match;
            }
        }
        
        // Pattern 3: Names before email patterns
        if (preg_match_all('/([A-Z][a-z]+\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s+.*?[a-z0-9._%+-]+@/', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $names[] = $match;
            }
        }
        
        // Pattern 4: Extract from document title
        if (!empty($documentTitle)) {
            $titleName = self::extractNameFromTitle($documentTitle);
            if ($titleName && $titleName !== 'Unknown') {
                $names[] = $titleName;
            }
        }
        
        // Filter out invalid names
        $validNames = array_filter($names, function($name) {
            return self::isValidName($name);
        });
        
        return array_unique($validNames);
    }
    
    /**
     * Extract name from document title
     */
    private static function extractNameFromTitle(string $title): ?string
    {
        // Remove file extensions
        $cleanTitle = preg_replace('/\.(pdf|docx?|txt|csv)$/i', '', $title);
        $cleanTitle = trim($cleanTitle);
        
        // Remove common resume/CV keywords
        $cleanTitle = preg_replace('/\b(resume|cv|cover\s+letter)\b/i', '', $cleanTitle);
        $cleanTitle = trim($cleanTitle);
        
        // Check if it looks like a name (2-4 words, proper case)
        $words = explode(' ', $cleanTitle);
        if (count($words) >= 2 && count($words) <= 4) {
            $validWords = array_filter($words, function($word) {
                return strlen($word) > 1 && preg_match('/^[A-Z][a-z]+$/', $word);
            });
            
            if (count($validWords) >= 2) {
                return implode(' ', $validWords);
            }
        }
        
        return null;
    }
    
    /**
     * Check if a name is valid (not too long, not common words, etc.)
     */
    private static function isValidName(string $name): bool
    {
        // Too long
        if (strlen($name) > 50) {
            return false;
        }
        
        // Contains parentheses (likely metadata)
        if (str_contains($name, '(') || str_contains($name, ')')) {
            return false;
        }
        
        // Contains common non-name words
        $badWords = [
            'document', 'file', 'resume', 'cv', 'pdf', 'docx', 'txt',
            'phone', 'email', 'address', 'objective', 'experience',
            'education', 'skills', 'work', 'job', 'company'
        ];
        
        $nameLower = strtolower($name);
        foreach ($badWords as $bad) {
            if (str_contains($nameLower, $bad)) {
                return false;
            }
        }
        
        // Must have at least 2 words
        $words = explode(' ', trim($name));
        if (count($words) < 2) {
            return false;
        }
        
        // Each word should be reasonable length
        foreach ($words as $word) {
            if (strlen($word) > 15) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check for partial name matches
     */
    private static function hasPartialMatch(string $requestedName, string $extractedName): bool
    {
        $requestedWords = explode(' ', $requestedName);
        $extractedWords = explode(' ', $extractedName);
        
        // Check if any word from requested name matches any word from extracted name
        foreach ($requestedWords as $requestedWord) {
            foreach ($extractedWords as $extractedWord) {
                if (strlen($requestedWord) > 2 && strlen($extractedWord) > 2) {
                    if ($requestedWord === $extractedWord || 
                        str_contains($extractedWord, $requestedWord) ||
                        str_contains($requestedWord, $extractedWord)) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Calculate confidence score for name matching
     */
    private static function calculateConfidence(array $exactMatches, array $partialMatches, string $requestedName): float
    {
        if (!empty($exactMatches)) {
            return 1.0; // Perfect match
        }
        
        if (!empty($partialMatches)) {
            return 0.6; // Partial match
        }
        
        return 0.0; // No match
    }
    
    /**
     * Generate intelligent response based on name matching results
     */
    public static function generateIntelligentResponse(array $matchingResult, string $originalQuery): string
    {
        $requestedName = $matchingResult['requested_name'];
        
        if ($matchingResult['has_exact_match']) {
            return "I found information about {$requestedName} in the available documents.";
        }
        
        if ($matchingResult['has_partial_match']) {
            $partialMatches = implode(', ', $matchingResult['partial_matches']);
            return "I don't have specific information about {$requestedName}, but I found similar names: {$partialMatches}. Would you like me to tell you about any of these people instead?";
        }
        
        if (!empty($matchingResult['all_found_names'])) {
            $allNames = implode(', ', $matchingResult['all_found_names']);
            return "I don't have information about {$requestedName} in the available documents. However, I do have information about: {$allNames}. Would you like me to tell you about any of these people instead?";
        }
        
        return "I don't have any information about {$requestedName} in the available documents. The documents don't contain any person information that I can share.";
    }
    
    /**
     * CRITICAL SECURITY: Filter snippets by user permissions
     * Only allow access to documents the user has permission to view
     */
    private static function filterSnippetsByUserPermissions(array $snippets, string $orgId, string $userId = null): array
    {
        if (!$userId) {
            Log::warning('🚨 No user ID provided for name matching - returning empty results for security');
            return [];
        }
        
        $filteredSnippets = [];
        
        foreach ($snippets as $snippet) {
            $documentId = $snippet['document_id'] ?? null;
            if (!$documentId) {
                continue;
            }
            
            // Get document with connector information
            $document = \App\Models\Document::with('connector.userPermissions')
                ->where('id', $documentId)
                ->where('org_id', $orgId)
                ->first();
                
            if (!$document) {
                continue;
            }
            
            $connector = $document->connector;
            if (!$connector) {
                continue;
            }
            
            // Check user access to this document
            if (self::userHasAccessToDocument($connector, $userId)) {
                $filteredSnippets[] = $snippet;
            } else {
                Log::info('🚫 User denied access to document', [
                    'user_id' => $userId,
                    'document_id' => $documentId,
                    'connector_id' => $connector->id,
                    'connection_scope' => $connector->connection_scope
                ]);
            }
        }
        
        return $filteredSnippets;
    }
    
    /**
     * Check if user has access to a document based on connector permissions
     */
    private static function userHasAccessToDocument($connector, string $userId): bool
    {
        // Organization connectors: accessible to all org members
        if ($connector->connection_scope === 'organization') {
            return true;
        }
        
        // Personal connectors: only accessible to users with explicit permissions
        if ($connector->connection_scope === 'personal') {
            return $connector->userPermissions()
                ->where('user_id', $userId)
                ->exists();
        }
        
        // Default: deny access for unknown scopes
        return false;
    }
}
