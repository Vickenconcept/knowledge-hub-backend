<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotionService
{
    private $accessToken;
    private $apiVersion = '2022-06-28';

    public function __construct($accessToken = null)
    {
        $this->accessToken = $accessToken;
    }

    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Search for all pages and databases accessible to the integration
     */
    public function searchPages($startCursor = null)
    {
        try {
            $payload = [
                'page_size' => 100,
                // Don't filter - get both pages AND databases
            ];

            if ($startCursor) {
                $payload['start_cursor'] = $startCursor;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Notion-Version' => $this->apiVersion,
                'Content-Type' => 'application/json',
            ])
            ->withOptions([
                'verify' => false, // Disable SSL verification for local development
            ])
            ->timeout(60)
            ->post('https://api.notion.com/v1/search', $payload);

            if (!$response->successful()) {
                Log::error('Notion search failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Failed to search Notion pages: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Notion search error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all blocks (content) for a page
     */
    public function getPageBlocks($pageId, $startCursor = null)
    {
        try {
            $url = "https://api.notion.com/v1/blocks/{$pageId}/children";
            
            if ($startCursor) {
                $url .= '?start_cursor=' . $startCursor;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Notion-Version' => $this->apiVersion,
            ])
            ->withOptions([
                'verify' => false, // Disable SSL verification for local development
            ])
            ->timeout(60)
            ->get($url);

            if (!$response->successful()) {
                Log::error('Notion get blocks failed', [
                    'page_id' => $pageId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Failed to get Notion page blocks: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Notion get blocks error: ' . $e->getMessage(), ['page_id' => $pageId]);
            throw $e;
        }
    }

    /**
     * Get page details/metadata
     */
    public function getPage($pageId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Notion-Version' => $this->apiVersion,
            ])
            ->withOptions([
                'verify' => false, // Disable SSL verification for local development
            ])
            ->timeout(60)
            ->get("https://api.notion.com/v1/pages/{$pageId}");

            if (!$response->successful()) {
                Log::error('Notion get page failed', [
                    'page_id' => $pageId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Failed to get Notion page: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Notion get page error: ' . $e->getMessage(), ['page_id' => $pageId]);
            throw $e;
        }
    }

    /**
     * Extract plain text from Notion blocks
     */
    public function extractTextFromBlocks($blocks)
    {
        $text = '';

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            
            // Extract text from different block types
            switch ($type) {
                case 'paragraph':
                    $text .= $this->extractRichText($block['paragraph']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'heading_1':
                    $text .= "# " . $this->extractRichText($block['heading_1']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'heading_2':
                    $text .= "## " . $this->extractRichText($block['heading_2']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'heading_3':
                    $text .= "### " . $this->extractRichText($block['heading_3']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'bulleted_list_item':
                    $text .= "• " . $this->extractRichText($block['bulleted_list_item']['rich_text'] ?? []) . "\n";
                    break;
                    
                case 'numbered_list_item':
                    $text .= "- " . $this->extractRichText($block['numbered_list_item']['rich_text'] ?? []) . "\n";
                    break;
                    
                case 'to_do':
                    $checked = $block['to_do']['checked'] ?? false;
                    $checkbox = $checked ? '[x]' : '[ ]';
                    $text .= "{$checkbox} " . $this->extractRichText($block['to_do']['rich_text'] ?? []) . "\n";
                    break;
                    
                case 'toggle':
                    $text .= $this->extractRichText($block['toggle']['rich_text'] ?? []) . "\n";
                    break;
                    
                case 'quote':
                    $text .= "> " . $this->extractRichText($block['quote']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'code':
                    $code = $this->extractRichText($block['code']['rich_text'] ?? []);
                    $language = $block['code']['language'] ?? 'text';
                    $text .= "```{$language}\n{$code}\n```\n\n";
                    break;
                    
                case 'callout':
                    $text .= "📌 " . $this->extractRichText($block['callout']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'divider':
                    $text .= "---\n\n";
                    break;
            }
        }

        return trim($text);
    }

    /**
     * Extract plain text from Notion rich text array
     */
    private function extractRichText($richTextArray)
    {
        $text = '';
        
        foreach ($richTextArray as $richText) {
            $text .= $richText['plain_text'] ?? '';
        }
        
        return $text;
    }

    /**
     * Get page title from Notion page object
     */
    public function getPageTitle($page)
    {
        $properties = $page['properties'] ?? [];
        
        // Try to find title in different property types
        foreach ($properties as $property) {
            if (isset($property['title']) && is_array($property['title'])) {
                $title = $this->extractRichText($property['title']);
                if (!empty($title)) {
                    return $title;
                }
            }
        }
        
        // Fallback to page object title
        if (isset($page['title']) && is_array($page['title'])) {
            return $this->extractRichText($page['title']);
        }
        
        return 'Untitled Page';
    }

    /**
     * Get page URL
     */
    public function getPageUrl($pageId)
    {
        // Remove hyphens from UUID for Notion URL format
        $cleanId = str_replace('-', '', $pageId);
        return "https://www.notion.so/{$cleanId}";
    }

    /**
     * Query a database to get all its items (rows)
     */
    public function queryDatabase($databaseId, $startCursor = null)
    {
        try {
            $payload = [
                'page_size' => 100,
            ];

            if ($startCursor) {
                $payload['start_cursor'] = $startCursor;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Notion-Version' => $this->apiVersion,
                'Content-Type' => 'application/json',
            ])
            ->withOptions([
                'verify' => false,
            ])
            ->timeout(60)
            ->post("https://api.notion.com/v1/databases/{$databaseId}/query", $payload);

            if (!$response->successful()) {
                Log::error('Notion database query failed', [
                    'database_id' => $databaseId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Failed to query Notion database: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Notion database query error: ' . $e->getMessage(), ['database_id' => $databaseId]);
            throw $e;
        }
    }

    /**
     * Extract text from database item (page properties)
     */
    public function extractDatabaseItemText($item)
    {
        $text = '';
        $properties = $item['properties'] ?? [];

        foreach ($properties as $propName => $property) {
            $type = $property['type'] ?? '';
            
            try {
                switch ($type) {
                    case 'title':
                        $title = $this->extractRichText($property['title'] ?? []);
                        if ($title) $text .= "**{$propName}:** {$title}\n";
                        break;
                        
                    case 'rich_text':
                        $richText = $this->extractRichText($property['rich_text'] ?? []);
                        if ($richText) $text .= "**{$propName}:** {$richText}\n";
                        break;
                        
                    case 'select':
                        if (isset($property['select']['name'])) {
                            $text .= "**{$propName}:** {$property['select']['name']}\n";
                        }
                        break;
                        
                    case 'multi_select':
                        if (!empty($property['multi_select']) && is_array($property['multi_select'])) {
                            $values = array_map(fn($s) => $s['name'] ?? '', $property['multi_select']);
                            $text .= "**{$propName}:** " . implode(', ', $values) . "\n";
                        }
                        break;
                        
                    case 'number':
                        if (isset($property['number']) && !is_null($property['number'])) {
                            $text .= "**{$propName}:** {$property['number']}\n";
                        }
                        break;
                        
                    case 'url':
                        if (isset($property['url']) && is_string($property['url'])) {
                            $text .= "**{$propName}:** {$property['url']}\n";
                        }
                        break;
                        
                    case 'email':
                        if (isset($property['email']) && is_string($property['email'])) {
                            $text .= "**{$propName}:** {$property['email']}\n";
                        }
                        break;
                        
                    case 'phone_number':
                        if (isset($property['phone_number']) && is_string($property['phone_number'])) {
                            $text .= "**{$propName}:** {$property['phone_number']}\n";
                        }
                        break;
                        
                    case 'date':
                        if (isset($property['date']['start'])) {
                            $dateStr = $property['date']['start'];
                            if (isset($property['date']['end'])) {
                                $dateStr .= ' → ' . $property['date']['end'];
                            }
                            $text .= "**{$propName}:** {$dateStr}\n";
                        }
                        break;
                        
                    case 'checkbox':
                        if (isset($property['checkbox'])) {
                            $checked = $property['checkbox'] ? '✓' : '✗';
                            $text .= "**{$propName}:** {$checked}\n";
                        }
                        break;
                        
                    case 'status':
                        if (isset($property['status']['name'])) {
                            $text .= "**{$propName}:** {$property['status']['name']}\n";
                        }
                        break;
                        
                    case 'people':
                        if (!empty($property['people']) && is_array($property['people'])) {
                            $names = array_map(fn($p) => $p['name'] ?? 'Unknown', $property['people']);
                            $text .= "**{$propName}:** " . implode(', ', $names) . "\n";
                        }
                        break;
                        
                    case 'files':
                        if (!empty($property['files']) && is_array($property['files'])) {
                            $fileNames = array_map(fn($f) => $f['name'] ?? 'File', $property['files']);
                            $text .= "**{$propName}:** " . implode(', ', $fileNames) . "\n";
                        }
                        break;
                        
                    case 'relation':
                        // Relations are just references - skip or note count
                        if (!empty($property['relation']) && is_array($property['relation'])) {
                            $count = count($property['relation']);
                            $text .= "**{$propName}:** {$count} related item(s)\n";
                        }
                        break;
                        
                    case 'formula':
                        // Extract formula result
                        if (isset($property['formula'])) {
                            $formulaType = $property['formula']['type'] ?? '';
                            $result = $property['formula'][$formulaType] ?? '';
                            if (!empty($result) && is_scalar($result)) {
                                $text .= "**{$propName}:** {$result}\n";
                            }
                        }
                        break;
                        
                    case 'rollup':
                        // Extract rollup result
                        if (isset($property['rollup'])) {
                            $rollupType = $property['rollup']['type'] ?? '';
                            $result = $property['rollup'][$rollupType] ?? '';
                            if (!empty($result) && is_scalar($result)) {
                                $text .= "**{$propName}:** {$result}\n";
                            }
                        }
                        break;
                        
                    case 'created_time':
                        if (isset($property['created_time'])) {
                            $text .= "**{$propName}:** {$property['created_time']}\n";
                        }
                        break;
                        
                    case 'last_edited_time':
                        if (isset($property['last_edited_time'])) {
                            $text .= "**{$propName}:** {$property['last_edited_time']}\n";
                        }
                        break;
                        
                    case 'created_by':
                    case 'last_edited_by':
                        // User info - skip for now
                        break;
                        
                    default:
                        // Unknown property type - log for debugging
                        Log::debug("Unknown Notion property type: {$type}", [
                            'property_name' => $propName,
                            'property' => $property
                        ]);
                        break;
                }
            } catch (\Throwable $e) {
                // Catch any property extraction errors
                Log::warning("Failed to extract Notion property", [
                    'property_name' => $propName,
                    'type' => $type,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $text . "\n";
    }

    /**
     * Recursively extract text from blocks including child blocks
     */
    public function extractTextFromBlocksRecursive($blocks, $depth = 0)
    {
        $text = '';
        $indent = str_repeat('  ', $depth);

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            $hasChildren = $block['has_children'] ?? false;
            
            // Extract text from current block
            switch ($type) {
                case 'paragraph':
                    $text .= $indent . $this->extractRichText($block['paragraph']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'heading_1':
                    $text .= $indent . "# " . $this->extractRichText($block['heading_1']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'heading_2':
                    $text .= $indent . "## " . $this->extractRichText($block['heading_2']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'heading_3':
                    $text .= $indent . "### " . $this->extractRichText($block['heading_3']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'bulleted_list_item':
                    $text .= $indent . "• " . $this->extractRichText($block['bulleted_list_item']['rich_text'] ?? []) . "\n";
                    break;
                    
                case 'numbered_list_item':
                    $text .= $indent . "- " . $this->extractRichText($block['numbered_list_item']['rich_text'] ?? []) . "\n";
                    break;
                    
                case 'to_do':
                    $checked = $block['to_do']['checked'] ?? false;
                    $checkbox = $checked ? '[x]' : '[ ]';
                    $text .= $indent . "{$checkbox} " . $this->extractRichText($block['to_do']['rich_text'] ?? []) . "\n";
                    break;
                    
                case 'toggle':
                    $text .= $indent . $this->extractRichText($block['toggle']['rich_text'] ?? []) . "\n";
                    break;
                    
                case 'quote':
                    $text .= $indent . "> " . $this->extractRichText($block['quote']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'code':
                    $code = $this->extractRichText($block['code']['rich_text'] ?? []);
                    $language = $block['code']['language'] ?? 'text';
                    $text .= $indent . "```{$language}\n{$code}\n```\n\n";
                    break;
                    
                case 'callout':
                    $text .= $indent . "📌 " . $this->extractRichText($block['callout']['rich_text'] ?? []) . "\n\n";
                    break;
                    
                case 'divider':
                    $text .= $indent . "---\n\n";
                    break;

                case 'child_page':
                    // Reference to a child page
                    $childTitle = $block['child_page']['title'] ?? 'Untitled';
                    $text .= $indent . "→ Child page: {$childTitle}\n";
                    break;

                case 'table':
                    $text .= $indent . "[Table content]\n";
                    break;
            }

            // Recursively fetch child blocks if they exist
            if ($hasChildren && $depth < 5) { // Limit recursion depth to prevent infinite loops
                try {
                    $blockId = $block['id'];
                    $childBlocks = $this->getPageBlocks($blockId);
                    $childBlocksList = $childBlocks['results'] ?? [];
                    
                    if (!empty($childBlocksList)) {
                        $text .= $this->extractTextFromBlocksRecursive($childBlocksList, $depth + 1);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to fetch child blocks', [
                        'block_id' => $block['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $text;
    }
}
