<?php

namespace App\Services;

use App\Models\CostTracking;
use Illuminate\Support\Facades\Log;

/**
 * Cost Tracking Service
 * Calculates and tracks OpenAI API costs for embeddings and chat
 */
class CostTrackingService
{
    /**
     * OpenAI Pricing (as of 2025)
     * Update these if pricing changes
     */
    private const PRICING = [
        // Embedding models (per 1M tokens)
        'text-embedding-3-small' => ['input' => 0.02],
        'text-embedding-3-large' => ['input' => 0.13],
        'text-embedding-ada-002' => ['input' => 0.10],
        
        // Chat models (per 1M tokens)
        'gpt-4o' => ['input' => 5.00, 'output' => 15.00],
        'gpt-4o-mini' => ['input' => 0.150, 'output' => 0.600],
        'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
        'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],
    ];

    /**
     * Track embedding operation cost
     */
    public static function trackEmbedding(
        string $orgId,
        string $model,
        int $tokens,
        ?string $documentId = null,
        ?string $ingestJobId = null
    ): void {
        $cost = self::calculateEmbeddingCost($model, $tokens);
        
        CostTracking::create([
            'org_id' => $orgId,
            'operation_type' => 'embedding',
            'model_used' => $model,
            'provider' => 'openai',
            'tokens_input' => $tokens,
            'tokens_output' => 0,
            'total_tokens' => $tokens,
            'cost_usd' => $cost,
            'document_id' => $documentId,
            'ingest_job_id' => $ingestJobId,
        ]);
        
        Log::info('Cost tracked: embedding', [
            'org_id' => $orgId,
            'tokens' => $tokens,
            'cost_usd' => $cost,
        ]);
    }

    /**
     * Track chat/LLM operation cost
     */
    public static function trackChat(
        string $orgId,
        ?int $userId,
        string $model,
        int $tokensInput,
        int $tokensOutput,
        string $queryText,
        ?string $conversationId = null
    ): void {
        $cost = self::calculateChatCost($model, $tokensInput, $tokensOutput);
        
        CostTracking::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'operation_type' => 'chat',
            'model_used' => $model,
            'provider' => 'openai',
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'total_tokens' => $tokensInput + $tokensOutput,
            'cost_usd' => $cost,
            'conversation_id' => $conversationId,
            'query_text' => mb_substr($queryText, 0, 500), // Truncate long queries
        ]);
        
        Log::info('Cost tracked: chat', [
            'org_id' => $orgId,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'cost_usd' => $cost,
        ]);
    }

    /**
     * Calculate embedding cost
     */
    private static function calculateEmbeddingCost(string $model, int $tokens): float
    {
        $pricing = self::PRICING[$model] ?? self::PRICING['text-embedding-3-small'];
        $pricePerMillion = $pricing['input'];
        
        return round(($tokens / 1_000_000) * $pricePerMillion, 6);
    }

    /**
     * Calculate chat/completion cost
     */
    private static function calculateChatCost(string $model, int $tokensInput, int $tokensOutput): float
    {
        $pricing = self::PRICING[$model] ?? self::PRICING['gpt-4o-mini'];
        
        $inputCost = ($tokensInput / 1_000_000) * $pricing['input'];
        $outputCost = ($tokensOutput / 1_000_000) * $pricing['output'];
        
        return round($inputCost + $outputCost, 6);
    }

    /**
     * Get org cost statistics
     */
    public static function getOrgStats(string $orgId, ?string $period = 'month'): array
    {
        $query = CostTracking::where('org_id', $orgId);
        
        // Filter by period
        if ($period === 'day') {
            $query->where('created_at', '>=', now()->startOfDay());
        } elseif ($period === 'week') {
            $query->where('created_at', '>=', now()->startOfWeek());
        } elseif ($period === 'month') {
            $query->where('created_at', '>=', now()->startOfMonth());
        } elseif ($period === 'all') {
            // No filter
        }
        
        $records = $query->get();
        
        // Aggregate by operation type
        $stats = [
            'total_cost' => $records->sum('cost_usd'),
            'total_tokens' => $records->sum('total_tokens'),
            'total_operations' => $records->count(),
            
            'by_operation' => [
                'embedding' => [
                    'count' => $records->where('operation_type', 'embedding')->count(),
                    'tokens' => $records->where('operation_type', 'embedding')->sum('total_tokens'),
                    'cost' => $records->where('operation_type', 'embedding')->sum('cost_usd'),
                ],
                'chat' => [
                    'count' => $records->where('operation_type', 'chat')->count(),
                    'tokens' => $records->where('operation_type', 'chat')->sum('total_tokens'),
                    'cost' => $records->where('operation_type', 'chat')->sum('cost_usd'),
                ],
                'summarization' => [
                    'count' => $records->where('operation_type', 'summarization')->count(),
                    'tokens' => $records->where('operation_type', 'summarization')->sum('total_tokens'),
                    'cost' => $records->where('operation_type', 'summarization')->sum('cost_usd'),
                ],
                'vector_query' => [
                    'count' => $records->where('operation_type', 'vector_query')->count(),
                    'tokens' => $records->where('operation_type', 'vector_query')->sum('total_tokens'),
                    'total_tokens' => $records->where('operation_type', 'vector_query')->sum('total_tokens'),
                    'cost' => $records->where('operation_type', 'vector_query')->sum('cost_usd'),
                ],
                'vector_upsert' => [
                    'count' => $records->where('operation_type', 'vector_upsert')->count(),
                    'tokens' => $records->where('operation_type', 'vector_upsert')->sum('total_tokens'),
                    'total_tokens' => $records->where('operation_type', 'vector_upsert')->sum('total_tokens'),
                    'cost' => $records->where('operation_type', 'vector_upsert')->sum('cost_usd'),
                ],
                'file_pull' => [
                    'count' => $records->where('operation_type', 'file_pull')->count(),
                    'tokens' => $records->where('operation_type', 'file_pull')->sum('total_tokens'), // KB
                    'tokens_input' => $records->where('operation_type', 'file_pull')->sum('tokens_input'), // File count
                    'total_tokens' => $records->where('operation_type', 'file_pull')->sum('total_tokens'), // KB
                    'cost' => $records->where('operation_type', 'file_pull')->sum('cost_usd'),
                ],
            ],
            
            'by_model' => $records->groupBy('model_used')->map(function($group) {
                return [
                    'count' => $group->count(),
                    'tokens' => $group->sum('total_tokens'),
                    'cost' => $group->sum('cost_usd'),
                ];
            })->toArray(),
            
            'period' => $period,
            'start_date' => $period !== 'all' ? $query->min('created_at') : null,
            'end_date' => now(),
        ];
        
        return $stats;
    }

    /**
     * Get daily cost breakdown for charts
     */
    public static function getDailyCosts(string $orgId, int $days = 30): array
    {
        $costs = CostTracking::where('org_id', $orgId)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(created_at) as date, SUM(cost_usd) as total_cost, SUM(total_tokens) as total_tokens, COUNT(*) as operations')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        
        return $costs->map(function($item) {
            return [
                'date' => $item->date,
                'cost' => (float) $item->total_cost,
                'tokens' => $item->total_tokens,
                'operations' => $item->operations,
            ];
        })->toArray();
    }

    /**
     * Estimate cost for future operations
     */
    public static function estimateCost(string $operation, string $model, int $tokens): float
    {
        if ($operation === 'embedding') {
            return self::calculateEmbeddingCost($model, $tokens);
        } else {
            // Assume 50/50 split for input/output for estimates
            return self::calculateChatCost($model, $tokens / 2, $tokens / 2);
        }
    }

    /**
     * Track vector query operation
     * 
     * @deprecated Since 2025-10-15 - Vector queries are now free (MySQL-based)
     * This method is kept for backward compatibility but does nothing.
     * Vector search is now performed locally in MySQL with no external costs.
     */
    public static function trackVectorQuery(
        string $orgId,
        int $vectorCount = 1,
        int $topK = 6,
        ?string $conversationId = null
    ): void {
        // NO-OP: Vector queries are now free (MySQL-based)
        // We no longer track costs for vector operations since they're performed locally
        
        Log::debug('Vector query performed (no cost - MySQL-based)', [
            'org_id' => $orgId,
            'vector_count' => $vectorCount,
            'top_k' => $topK,
        ]);
    }

    /**
     * Track vector upsert operation
     * 
     * @deprecated Since 2025-10-15 - Vector storage is now free (MySQL-based)
     * This method is kept for backward compatibility but does nothing.
     * Vectors are now stored directly in MySQL chunks table with no external costs.
     */
    public static function trackVectorUpsert(
        string $orgId,
        int $vectorCount,
        ?string $documentId = null,
        ?string $ingestJobId = null
    ): void {
        // NO-OP: Vector storage is now free (MySQL-based)
        // We no longer track costs for vector operations since they're stored in MySQL
        
        Log::debug('Vectors stored in MySQL (no cost - local storage)', [
            'org_id' => $orgId,
            'vector_count' => $vectorCount,
            'document_id' => $documentId,
        ]);
    }

    /**
     * Track file pull from connector (Google Drive, Dropbox, etc.)
     */
    public static function trackFilePull(
        string $orgId,
        string $connectorType,
        int $fileCount = 1,
        int $totalBytes = 0,
        ?string $ingestJobId = null
    ): void {
        // Track connector API usage
        // Most APIs are free but have rate limits we want to monitor
        $cost = 0; // No direct cost, but we track for monitoring
        
        CostTracking::create([
            'org_id' => $orgId,
            'operation_type' => 'file_pull',
            'model_used' => $connectorType, // e.g., 'google_drive', 'dropbox'
            'provider' => 'connector',
            'tokens_input' => $fileCount,
            'tokens_output' => 0,
            'total_tokens' => (int) ($totalBytes / 1024), // Store KB
            'cost_usd' => $cost,
            'ingest_job_id' => $ingestJobId,
        ]);
        
        Log::info('Tracked file pull', [
            'org_id' => $orgId,
            'connector' => $connectorType,
            'file_count' => $fileCount,
            'total_bytes' => $totalBytes,
        ]);
    }

    /**
     * Track document ingestion for quota management
     * This is used to prevent quota gaming (delete/re-sync)
     */
    public static function trackDocumentIngestion(
        string $orgId,
        string $documentId,
        ?string $connectorId = null,
        ?string $ingestJobId = null
    ): void {
        // No direct cost, but we track for quota enforcement
        // Each document ingestion counts toward monthly limit
        $cost = 0;
        
        CostTracking::create([
            'org_id' => $orgId,
            'operation_type' => 'document_ingestion',
            'model_used' => 'quota_tracking',
            'provider' => 'system',
            'tokens_input' => 1, // 1 document
            'tokens_output' => 0,
            'total_tokens' => 1,
            'cost_usd' => $cost,
            'document_id' => $documentId,
            'ingest_job_id' => $ingestJobId,
            'metadata' => json_encode(['connector_id' => $connectorId]),
        ]);
        
        Log::debug('Tracked document ingestion for quota', [
            'org_id' => $orgId,
            'document_id' => $documentId,
        ]);
    }
}

