<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Confluence API Service
 *
 * Lightweight wrapper around the Confluence Cloud REST API (v1)
 * using API token authentication.
 */
class ConfluenceService
{
    private string $baseUrl;
    private string $email;
    private string $apiToken;

    public function __construct(string $baseUrl, string $email, string $apiToken)
    {
        // Ensure no trailing slash
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->email = $email;
        $this->apiToken = $apiToken;
    }

    private function client()
    {
        return Http::withBasicAuth($this->email, $this->apiToken)
            ->acceptJson()
            ->timeout(60)
            ->connectTimeout(30)
            ->retry(3, 2000);
    }

    /**
     * List pages in a space (simple pagination wrapper).
     *
     * @param string      $spaceKey
     * @param int         $limit
     * @param int         $start
     * @return array      [ 'results' => [...], 'next_start' => ?int ]
     */
    public function listPages(string $spaceKey, int $limit = 50, int $start = 0): array
    {
        $response = $this->client()->get(
            "{$this->baseUrl}/rest/api/content",
            [
                'spaceKey' => $spaceKey,
                'limit' => $limit,
                'start' => $start,
                'type' => 'page',
                'expand' => 'space',
            ]
        );

        if (!$response->successful()) {
            Log::error('Confluence listPages failed', [
                'space' => $spaceKey,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to list Confluence pages');
        }

        $json = $response->json();

        return [
            'results' => $json['results'] ?? [],
            'next_start' => isset($json['_links']['next'])
                ? $start + $limit
                : null,
        ];
    }

    /**
     * Get a single page with rendered HTML body.
     */
    public function getPage(string $pageId): array
    {
        $response = $this->client()->get(
            "{$this->baseUrl}/rest/api/content/{$pageId}",
            [
                'expand' => 'body.view,space,version',
            ]
        );

        if (!$response->successful()) {
            Log::error('Confluence getPage failed', [
                'page_id' => $pageId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to get Confluence page');
        }

        return $response->json();
    }

    /**
     * Extract plain text from Confluence page JSON (body.view).
     */
    public function extractTextFromPage(array $page): string
    {
        $html = $page['body']['view']['value'] ?? '';
        if ($html === '') {
            return '';
        }

        // Very simple HTML->text stripping; DocumentExtractionService will sanitize further.
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        return trim($text);
    }

    /**
     * Build the public URL for a Confluence page.
     */
    public function getPageUrl(array $page): ?string
    {
        // Cloud often exposes _links['webui'] relative path
        $webui = $page['_links']['webui'] ?? null;
        if ($webui) {
            return $this->baseUrl . $webui;
        }

        // Fallback: construct from id and space key
        $id = $page['id'] ?? null;
        $spaceKey = $page['space']['key'] ?? null;

        if ($id && $spaceKey) {
            return "{$this->baseUrl}/spaces/{$spaceKey}/pages/{$id}";
        }

        return null;
    }
}

