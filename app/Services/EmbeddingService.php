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

    public function embed(string $text, ?string $orgId = null, ?string $documentId = null, ?string $ingestJobId = null): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY not configured.');
        }

        $payload = [
            'model' => $this->model,
            'input' => $text,
        ];

        $resp = Http::withToken($this->apiKey)
            ->timeout(60) // 60 second timeout
            ->retry(3, 1000) // Retry 3 times with 1 second delay
            ->post('https://api.openai.com/v1/embeddings', $payload);
            
        if (!$resp->successful()) {
            $statusCode = $resp->status();
            $responseBody = $resp->body();
            $responseJson = $resp->json();
            
            // Extract error message from OpenAI response
            $errorMessage = 'Failed to create embedding';
            if (isset($responseJson['error']['message'])) {
                $errorMessage = $responseJson['error']['message'];
            } elseif (is_string($responseBody)) {
                $errorMessage = $responseBody;
            }
            
            // Log comprehensive error details
            Log::error('EmbeddingService error', [
                'status_code' => $statusCode,
                'error_message' => $errorMessage,
                'response_body' => $responseBody,
                'org_id' => $orgId,
                'document_id' => $documentId,
                'ingest_job_id' => $ingestJobId,
                'model' => $this->model,
            ]);
            
            // Throw exception with detailed error message
            throw new \RuntimeException("HTTP request returned status code {$statusCode}:\n{$responseBody}");
        }

        $json = $resp->json();
        
        // Track cost if org_id is provided
        if ($orgId) {
            $usage = $json['usage'] ?? [];
            $totalTokens = $usage['total_tokens'] ?? 0;
            
            if ($totalTokens > 0) {
                \App\Services\CostTrackingService::trackEmbedding(
                    $orgId,
                    $this->model,
                    $totalTokens,
                    $documentId,
                    $ingestJobId
                );
            }
        }
        
        return $json['data'][0]['embedding'] ?? [];
    }

    public function embedBatch(array $texts, ?string $orgId = null, ?string $documentId = null, ?string $ingestJobId = null): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY not configured.');
        }

        $payload = [
            'model' => $this->model,
            'input' => array_values($texts),
        ];

        $resp = Http::withToken($this->apiKey)
            ->timeout(60) // 60 second timeout
            ->retry(3, 1000) // Retry 3 times with 1 second delay
            ->post('https://api.openai.com/v1/embeddings', $payload);
            
        if (!$resp->successful()) {
            $statusCode = $resp->status();
            $responseBody = $resp->body();
            $responseJson = $resp->json();
            
            // Extract error message from OpenAI response
            $errorMessage = 'Failed to create batch embeddings';
            if (isset($responseJson['error']['message'])) {
                $errorMessage = $responseJson['error']['message'];
            } elseif (is_string($responseBody)) {
                $errorMessage = $responseBody;
            }
            
            // Log comprehensive error details
            Log::error('EmbeddingService batch error', [
                'status_code' => $statusCode,
                'error_message' => $errorMessage,
                'response_body' => $responseBody,
                'org_id' => $orgId,
                'document_id' => $documentId,
                'ingest_job_id' => $ingestJobId,
                'batch_size' => count($texts),
                'model' => $this->model,
            ]);
            
            // Throw exception with detailed error message
            throw new \RuntimeException("HTTP request returned status code {$statusCode}:\n{$responseBody}");
        }

        $json = $resp->json();
        
        // Track cost if org_id is provided
        if ($orgId) {
            $usage = $json['usage'] ?? [];
            $totalTokens = $usage['total_tokens'] ?? 0;
            
            if ($totalTokens > 0) {
                \App\Services\CostTrackingService::trackEmbedding(
                    $orgId,
                    $this->model,
                    $totalTokens,
                    $documentId,
                    $ingestJobId
                );
            }
        }
        
        return array_map(fn($d) => $d['embedding'] ?? [], $json['data'] ?? []);
    }
}


