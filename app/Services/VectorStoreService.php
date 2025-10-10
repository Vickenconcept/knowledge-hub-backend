<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VectorStoreService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $index;
    protected ?int $targetDim = null;

    public function __construct()
    {
        $this->baseUrl = env('PINECONE_BASE_URL', '');
        $this->apiKey = env('PINECONE_API_KEY', '');
        $this->index = env('PINECONE_INDEX', '');
        $dim = (int) env('PINECONE_DIM', 0);
        $this->targetDim = $dim > 0 ? $dim : null;
        if (empty($this->baseUrl) || empty($this->apiKey) || empty($this->index)) {
            Log::warning('VectorStoreService not fully configured.');
        }
    }

    public function upsert(array $vectors, ?string $namespace = null, ?string $orgId = null, ?string $documentId = null, ?string $ingestJobId = null): array
    {
        if (empty($this->baseUrl) || empty($this->apiKey) || empty($this->index)) {
            // Graceful: treat as no-op in local/dev
            \Log::warning('Vector DB not configured; skipping upsert.');
            return [];
        }

        $isIndexHost = str_contains($this->baseUrl, '.svc.');
        $url = rtrim($this->baseUrl, '/');
        $url = $isIndexHost ? ($url . '/vectors/upsert') : ($url . '/indexes/' . $this->index . '/vectors/upsert');

        // Optionally project vectors to target dimension (truncate/pad)
        if ($this->targetDim !== null) {
            foreach ($vectors as &$v) {
                $v['values'] = $this->projectToDim($v['values']);
            }
            unset($v);
        }

        $payload = ['vectors' => $vectors];
        if (!empty($namespace)) {
            $payload['namespace'] = $namespace;
        }

        $resp = Http::withHeaders(['Api-Key' => $this->apiKey, 'Content-Type' => 'application/json'])
            ->timeout(60) // 60 second timeout
            ->retry(3, 1000) // Retry 3 times
            ->post($url, $payload);
            
        if (!$resp->successful()) {
            Log::error('VectorStore upsert failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            // Graceful: don't throw; let ingestion continue
            return [];
        }
        
        // Track Pinecone upsert cost if orgId is provided
        if ($orgId && !empty($vectors)) {
            \App\Services\CostTrackingService::trackVectorUpsert($orgId, count($vectors), $documentId, $ingestJobId);
        }
        
        return $resp->json();
    }

    public function query(array $embedding, int $topK = 6, ?string $namespace = null, ?string $orgId = null, ?string $conversationId = null, ?array $filter = null): array
    {
        if (empty($this->baseUrl) || empty($this->apiKey) || empty($this->index)) {
            return [];
        }

        $isIndexHost = str_contains($this->baseUrl, '.svc.');
        $url = rtrim($this->baseUrl, '/');
        $url = $isIndexHost ? ($url . '/query') : ($url . '/indexes/' . $this->index . '/query');
        // Optionally project query vector as well
        if ($this->targetDim !== null) {
            $embedding = $this->projectToDim($embedding);
        }

        $payload = [
            'vector' => $embedding,
            'topK' => $topK,
            'includeMetadata' => true,
            'includeValues' => false,
        ];
        if (!empty($namespace)) {
            $payload['namespace'] = $namespace;
        }
        
        // Add metadata filter for source filtering
        if (!empty($filter)) {
            $payload['filter'] = $filter;
            Log::info('Applying Pinecone metadata filter', ['filter' => $filter]);
        }
        $resp = Http::withHeaders(['Api-Key' => $this->apiKey, 'Content-Type' => 'application/json'])
            ->timeout(60) // 60 second timeout
            ->retry(3, 1000) // Retry 3 times
            ->post($url, $payload);
            
        if (!$resp->successful()) {
            Log::error('VectorStore query failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            throw new \RuntimeException('Vector query failed.');
        }
        $json = $resp->json();
        $matches = [];
        if (isset($json['matches'])) {
            foreach ($json['matches'] as $m) {
                $matches[] = [
                    'id' => $m['id'] ?? null,
                    'score' => $m['score'] ?? null,
                    'metadata' => $m['metadata'] ?? null,
                ];
            }
        } elseif (isset($json['results'][0]['matches'])) {
            foreach ($json['results'][0]['matches'] as $m) {
                $matches[] = [
                    'id' => $m['id'] ?? null,
                    'score' => $m['score'] ?? null,
                    'metadata' => $m['metadata'] ?? null,
                ];
            }
        }
        
        // Track Pinecone query cost if orgId is provided
        if ($orgId) {
            \App\Services\CostTrackingService::trackVectorQuery($orgId, 1, $topK, $conversationId);
        }
        
        return $matches;
    }

    public function delete(array $ids): array
    {
        if (empty($this->baseUrl) || empty($this->apiKey) || empty($this->index)) {
            return [];
        }
        $isIndexHost = str_contains($this->baseUrl, '.svc.');
        $url = rtrim($this->baseUrl, '/');
        $url = $isIndexHost ? ($url . '/vectors/delete') : ($url . '/indexes/' . $this->index . '/vectors/delete');
        $resp = Http::withHeaders(['Api-Key' => $this->apiKey, 'Content-Type' => 'application/json'])->post($url, ['ids' => $ids]);
        if (!$resp->successful()) {
            Log::error('VectorStore delete failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            return [];
        }
        return $resp->json();
    }

    private function projectToDim(array $values): array
    {
        if ($this->targetDim === null) return $values;
        $current = count($values);
        if ($current === $this->targetDim) return $values;
        if ($current > $this->targetDim) {
            // Truncate
            return array_slice($values, 0, $this->targetDim);
        }
        // Pad with zeros
        return array_merge($values, array_fill(0, $this->targetDim - $current, 0.0));
    }
}


