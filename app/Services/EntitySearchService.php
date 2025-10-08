<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Chunk;
use Illuminate\Support\Facades\Log;

/**
 * Entity-Aware Search Service
 * Handles queries that ask about multiple entities (people, companies, products)
 * e.g., "Who knows Laravel?", "List all developers with React", "Which people have UI/UX experience?"
 */
class EntitySearchService
{
    /**
     * Detect if query is asking about multiple entities
     */
    public static function isEntityQuery(string $query): array
    {
        $query = strtolower(trim($query));
        
        $result = [
            'is_entity_query' => false,
            'entity_type' => null,
            'query_intent' => null,
            'skill_keywords' => [],
            'confidence' => 0.0,
            'is_count_query' => false, // Just want the count, not full details
        ];
        
        // 1. WHO/WHICH patterns - MUST combine action + entity type
        // This prevents false matches on generic "list" or "what" queries
        $whoPatterns = [
            // WHO patterns (people-focused)
            '/\b(who (knows?|has|uses?|works?))\b/',
            '/\b(which (people|persons?|users?|developers?|candidates?))\b/',
            
            // COUNT/HOW MANY patterns
            '/\b(how many|count|number of)\s+(people|persons?|users?|developers?|candidates?)\b/',
            
            // LIST/FIND patterns (with flexible spacing)
            '/\b(list|find|get|show)(\s+me)?(\s+list)?(\s+of)?(\s+all)?(\s+the)?(\s+unique)?\s+(people|persons?|users?|developers?|candidates?|companies|vendors?|products?)\b/',
            '/\b(give\s+me)(\s+list)?(\s+of)?(\s+all)?(\s+the)?(\s+unique)?\s+(people|persons?|users?|developers?|candidates?)\b/',
            
            // Entity type at start
            '/^(people|users?|developers?|candidates?|companies|products?) (who|that|with)\b/',
            
            // Unique/All entity queries
            '/\b(unique|all|every)\s+(people|persons?|users?|developers?)\b/',
        ];
        
        $hasEntityPattern = false;
        foreach ($whoPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                $hasEntityPattern = true;
                break;
            }
        }
        
        // Check if it's a count query
        if (preg_match('/\b(how many|count|number of)\b/', $query)) {
            $result['is_count_query'] = true;
        }
        
        // Only mark as entity query if we have a clear pattern
        if ($hasEntityPattern) {
            $result['is_entity_query'] = true;
                
                // Determine entity type from query
                if (preg_match('/\b(people|persons?|users?|individuals?|candidates?|developers?|engineers?|designers?|managers?|employees?)\b/', $query)) {
                    $result['entity_type'] = 'person';
                } elseif (preg_match('/\b(companies|organizations?|businesses?|vendors?|clients?|partners?)\b/', $query)) {
                    $result['entity_type'] = 'company';
                } elseif (preg_match('/\b(products?|services?|tools?|solutions?|apps?|platforms?)\b/', $query)) {
                    $result['entity_type'] = 'product';
                } else {
                    // Default to generic entity
                    $result['entity_type'] = 'entity';
                }
                
            $result['confidence'] = 0.9;
        }
        
        // 2. Detect query intent (skills, experience, location, etc.)
        if ($result['is_entity_query']) {
            // Skill-based queries
            if (preg_match('/\b(with|has|have|knows?|skilled in|proficient in|experienced in)\b.*\b(skills?|experience|expertise|knowledge)\b/i', $query)) {
                $result['query_intent'] = 'skill_search';
                $result['skill_keywords'] = self::extractSkillKeywords($query);
            }
            // Direct skill mention (e.g., "who knows Laravel")
            elseif (preg_match('/\b(knows?|uses?|works? with)\b/i', $query)) {
                $result['query_intent'] = 'skill_search';
                $result['skill_keywords'] = self::extractSkillKeywords($query);
            }
            // Experience-based
            elseif (preg_match('/\b(years? of experience|work experience|background in)\b/i', $query)) {
                $result['query_intent'] = 'experience_search';
            }
            // Location-based
            elseif (preg_match('/\b(in|from|located in|based in)\b.*\b(city|country|location)\b/i', $query)) {
                $result['query_intent'] = 'location_search';
            }
            else {
                $result['query_intent'] = 'general_person_search';
                $result['skill_keywords'] = self::extractSkillKeywords($query);
            }
        }
        
        if ($result['is_entity_query']) {
            Log::info('Entity query detected', [
                'query' => $query,
                'entity_type' => $result['entity_type'],
                'intent' => $result['query_intent'],
                'skills' => $result['skill_keywords'],
            ]);
        }
        
        return $result;
    }
    
    /**
     * Extract skill/attribute keywords from query
     * Generic extraction - works for any domain
     */
    private static function extractSkillKeywords(string $query): array
    {
        // Remove common question words and prepositions
        $stopWords = [
            'who', 'what', 'which', 'where', 'when', 'how', 'many',
            'knows', 'has', 'have', 'with', 'about', 'the', 'a', 'an',
            'list', 'all', 'find', 'get', 'show', 'me', 'give',
            'people', 'users', 'person', 'user', 'developers', 'candidates',
            'skills', 'skill', 'experience', 'expertise', 'knowledge',
            'and', 'or', 'in', 'to', 'for', 'as', 'their', 'my', 'that',
            'both', 'either', 'neither', 'not', 'but', 'worked', 'works',
            'based', 'located', 'from', 'at'
        ];
        
        // Extract words from query
        $words = preg_split('/\s+/', strtolower($query));
        
        // Filter out stopwords and short words
        $keywords = array_filter($words, function($word) use ($stopWords) {
            $word = trim($word, '?.,!;:');
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });
        
        // Clean and return
        return array_values(array_map(fn($w) => trim($w, '?.,!;:'), $keywords));
    }
    
    /**
     * Search for entities matching the criteria
     * Works for people, companies, products, or any entity type
     */
    public static function searchEntities(string $query, array $entityInfo, string $orgId): array
    {
        // Get documents based on entity type
        // For person queries, prioritize resumes but also search other docs
        $documentsQuery = Document::where('org_id', $orgId)->with('chunks');
        
        if ($entityInfo['entity_type'] === 'person') {
            // For people: get resumes first, then other docs with email addresses
            $documentsQuery->where(function($q) {
                $q->whereIn('doc_type', ['resume', 'cv', 'cover_letter'])
                  ->orWhereNotNull('metadata->emails');
            });
        }
        
        $documents = $documentsQuery->get();
        
        Log::info('Searching entities', [
            'total_documents' => $documents->count(),
            'query_intent' => $entityInfo['query_intent'],
            'skills' => $entityInfo['skill_keywords'],
        ]);
        
        $entities = [];
        $seenIdentifiers = []; // Deduplicate by email or unique identifier
        
        foreach ($documents as $document) {
            // Extract entity info from document (works for any entity type)
            $entityData = self::extractEntityInfo($document, $entityInfo);
            
            if ($entityData) {
                // Deduplicate by email or name (for people) or title (for products/companies)
                $identifier = $entityData['email'] ?? $entityData['name'] ?? 'no-id-' . $document->id;
                
                if (!isset($seenIdentifiers[$identifier])) {
                    $seenIdentifiers[$identifier] = true;
                    $entities[] = $entityData;
                } else {
                    // Merge data if same entity with multiple documents
                    foreach ($entities as &$existing) {
                        $existingId = $existing['email'] ?? $existing['name'] ?? null;
                        if ($existingId === $identifier) {
                            // Merge matched skills/attributes
                            if (!empty($entityData['matched_skills'])) {
                                $existing['matched_skills'] = array_unique(array_merge(
                                    $existing['matched_skills'] ?? [],
                                    $entityData['matched_skills']
                                ));
                            }
                            // Merge all skills/tags
                            if (!empty($entityData['all_skills'])) {
                                $existing['all_skills'] = array_unique(array_merge(
                                    $existing['all_skills'] ?? [],
                                    $entityData['all_skills']
                                ));
                            }
                            break;
                        }
                    }
                }
            }
        }
        
        return [
            'entities' => $entities,
            'total' => count($entities),
            'entity_type' => $entityInfo['entity_type'],
        ];
    }
    
    /**
     * Extract entity information from a document
     * Works for any entity type (person, company, product, etc.)
     */
    private static function extractEntityInfo(Document $document, array $entityInfo): ?array
    {
        // Get all chunks for this document
        $allText = $document->chunks->pluck('text')->implode(' ');
        
        // Extract entity name/identifier
        // For people: extract person name
        // For companies/products: use document title or extract from content
        if ($entityInfo['entity_type'] === 'person') {
            $name = self::extractName($document->title, $allText);
            
            // Validate it's a real person name (skip if it looks like a document title)
            if (!self::looksLikePersonName($name)) {
                return null; // Skip this document - not a person
            }
        } else {
            // For non-person entities, use cleaned document title
            $name = self::cleanEntityName($document->title);
        }
        
        // Check if this entity matches the criteria
        if ($entityInfo['query_intent'] === 'skill_search' && !empty($entityInfo['skill_keywords'])) {
            $matchedSkills = [];
            $allTextLower = strtolower($allText);
            
            foreach ($entityInfo['skill_keywords'] as $skill) {
                if (stripos($allTextLower, $skill) !== false) {
                    $matchedSkills[] = $skill;
                }
            }
            
            // If no skills/attributes matched, skip this entity
            if (empty($matchedSkills)) {
                return null;
            }
            
            return [
                'name' => $name,
                'document_id' => $document->id,
                'document_title' => $document->title,
                'matched_skills' => $matchedSkills,
                'all_skills' => $document->tags ?? [],
                'email' => self::extractEmail($allText),
                'phone' => self::extractPhone($allText),
                'summary' => mb_substr($allText, 0, 200) . '...',
                'source_type' => $document->connector->type ?? 'unknown',
            ];
        }
        
        // For general queries, return basic info
        return [
            'name' => $name,
            'document_id' => $document->id,
            'document_title' => $document->title,
            'all_skills' => $document->tags ?? [],
            'email' => self::extractEmail($allText),
            'phone' => self::extractPhone($allText),
            'summary' => mb_substr($allText, 0, 200) . '...',
            'source_type' => $document->connector->type ?? 'unknown',
        ];
    }
    
    /**
     * Check if extracted name looks like an actual person name
     */
    private static function looksLikePersonName(string $name): bool
    {
        // Reject if too long (likely a document title)
        if (strlen($name) > 50) {
            return false;
        }
        
        // Reject if contains parentheses (likely document title with metadata)
        if (str_contains($name, '(') || str_contains($name, ')')) {
            return false;
        }
        
        // Reject if contains common non-name words
        $badWords = [
            'swipe', 'dfy', 'document', 'untitled', 'spreadsheet', 'new', 'project',
            'programming', 'technical', 'issues', 'certificate', 'links', 'data',
            'promo', 'contest', 'thanks', 'study', 'odoo', 'fluencegrid'
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
        
        // Each word should be reasonably short (< 15 chars)
        foreach ($words as $word) {
            if (strlen($word) > 15) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Clean entity name from document title (for companies, products, etc.)
     */
    private static function cleanEntityName(string $title): string
    {
        // Remove file extensions and common suffixes
        $cleaned = preg_replace('/\.(pdf|docx?|txt|csv)$/i', '', $title);
        $cleaned = trim($cleaned);
        
        return $cleaned ?: 'Unknown';
    }
    
    /**
     * Extract person's name from document
     */
    private static function extractName(string $title, string $text): string
    {
        // Clean up the title
        $cleanTitle = trim(str_replace([
            'resume', 'cv', '.pdf', '.docx', '-resume', '-cv', 
            '_resume', '_cv', 'php', 'ui-ux', 'cover letter'
        ], ' ', strtolower($title)));
        $cleanTitle = preg_replace('/\s+/', ' ', $cleanTitle); // Normalize spaces
        $cleanTitle = trim($cleanTitle);
        
        // Check if cleaned title is a valid name (2-3 words)
        $words = explode(' ', $cleanTitle);
        if (count($words) >= 2 && count($words) <= 4) {
            // Ensure words are not common non-name words
            $nonNameWords = ['the', 'and', 'or', 'for', 'with', 'document', 'file', 'new', 'old', '1', '2', '3'];
            $validWords = array_filter($words, fn($w) => !in_array(strtolower($w), $nonNameWords) && strlen($w) > 1);
            
            if (count($validWords) >= 2) {
                return ucwords(implode(' ', $validWords));
            }
        }
        
        // Try to find name at the very beginning of the text (first 500 chars)
        $textStart = mb_substr($text, 0, 500);
        
        // Pattern 1: All caps name at start (e.g., "WILLIAM VICTOR")
        if (preg_match('/^([A-Z]+\s+[A-Z]+(?:\s+[A-Z]+)?)\s/m', $textStart, $matches)) {
            return ucwords(strtolower($matches[1]));
        }
        
        // Pattern 2: Name before email pattern
        if (preg_match('/([A-Z][a-z]+\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s+.*?[a-z0-9._%+-]+@/m', $textStart, $matches)) {
            return $matches[1];
        }
        
        // Pattern 3: Name before phone/contact info
        if (preg_match('/([A-Z][a-z]+\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s+.*?(Telephone:|Phone:|Tel:|Email:|\+?\d{3})/m', $textStart, $matches)) {
            return $matches[1];
        }
        
        // Fallback to cleaned title
        return ucwords($cleanTitle) ?: 'Unknown';
    }
    
    /**
     * Extract email from text
     */
    private static function extractEmail(string $text): ?string
    {
        if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $text, $matches)) {
            return $matches[0];
        }
        return null;
    }
    
    /**
     * Extract phone from text
     */
    private static function extractPhone(string $text): ?string
    {
        // Match various phone formats
        // Pattern 1: International format (+234 903 680 2727)
        if (preg_match('/\+?\d{1,4}[\s.-]?\(?\d{2,4}\)?[\s.-]?\d{3,4}[\s.-]?\d{3,4}/', $text, $matches)) {
            return $matches[0];
        }
        // Pattern 2: Simple format (09036802727)
        if (preg_match('/\b0?\d{10,11}\b/', $text, $matches)) {
            return $matches[0];
        }
        return null;
    }
    
    /**
     * Format entity search results for display
     */
    public static function formatEntityResults(array $results, string $query, bool $isCountQuery = false): string
    {
        // Dynamic entity label
        $entityLabel = match($results['entity_type'] ?? 'entity') {
            'person' => $results['total'] === 1 ? 'person' : 'people',
            'company' => $results['total'] === 1 ? 'company' : 'companies',
            'product' => $results['total'] === 1 ? 'product' : 'products',
            default => $results['total'] === 1 ? 'result' : 'results',
        };
        
        if ($results['total'] === 0) {
            return "0 {$entityLabel} in your knowledge base match this criteria.";
        }
        
        // For count queries, provide a concise count-focused response
        if ($isCountQuery) {
            $response = "{$results['total']} {$entityLabel} in your knowledge base match this criteria.\n\n";
            
            // List just names for count queries
            $names = array_column($results['entities'], 'name');
            $response .= "• " . implode("\n• ", $names);
            
            return $response;
        }
        
        $response = "Found {$results['total']} {$entityLabel} matching your query:\n\n";
        
        foreach ($results['entities'] as $entity) {
            // Dynamic icon based on entity type
            $icon = match($results['entity_type'] ?? 'entity') {
                'person' => '👤',
                'company' => '🏢',
                'product' => '📦',
                default => '📄',
            };
            
            $response .= "{$icon} {$entity['name']}\n";
            
            if (!empty($entity['matched_skills'])) {
                $response .= "   Matched Skills: " . implode(', ', array_map('ucfirst', $entity['matched_skills'])) . "\n";
            }
            
            if (!empty($entity['all_skills']) && count($entity['all_skills']) > count($entity['matched_skills'] ?? [])) {
                $otherSkills = array_diff($entity['all_skills'], $entity['matched_skills'] ?? []);
                // Filter out non-skill words
                $otherSkills = array_filter($otherSkills, fn($s) => !in_array(strtolower($s), ['resume', 'cv', 'cover', 'letter', 'cover_letter']));
                if (!empty($otherSkills)) {
                    $response .= "   Other Skills: " . implode(', ', array_slice(array_map('ucfirst', $otherSkills), 0, 10)) . "\n";
                }
            }
            
            if ($entity['email']) {
                $response .= "   📧 {$entity['email']}\n";
            }
            
            if ($entity['phone']) {
                $response .= "   📞 {$entity['phone']}\n";
            }
            
            $response .= "\n";
        }
        
        return trim($response);
    }
}

