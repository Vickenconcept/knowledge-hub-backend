<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.key', env('OPENAI_API_KEY'));
        $this->model = env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small');
    }

    public function embed(string $text): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY not configured.');
        }

        $payload = [
            'model' => $this->model,
            'input' => $text,
        ];

        $resp = Http::withToken($this->apiKey)->post('https://api.openai.com/v1/embeddings', $payload);
        if (!$resp->successful()) {
            Log::error('EmbeddingService error', ['status' => $resp->status(), 'body' => $resp->body()]);
            throw new \RuntimeException('Failed to create embedding.');
        }

        $json = $resp->json();
        return $json['data'][0]['embedding'] ?? [];
    }

    public function embedBatch(array $texts): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY not configured.');
        }

        $payload = [
            'model' => $this->model,
            'input' => array_values($texts),
        ];

        $resp = Http::withToken($this->apiKey)->post('https://api.openai.com/v1/embeddings', $payload);
        if (!$resp->successful()) {
            Log::error('EmbeddingService batch error', ['status' => $resp->status(), 'body' => $resp->body()]);
            throw new \RuntimeException('Failed to create batch embeddings.');
        }

        $json = $resp->json();
        return array_map(fn($d) => $d['embedding'] ?? [], $json['data'] ?? []);
    }
}


