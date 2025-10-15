<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * VectorStoreService - MySQL BLOB-based Vector Storage
 * 
 * Stores vector embeddings directly in MySQL database using BLOB storage.
 * Performs cosine similarity search in PHP for fast retrieval.
 * 
 * This replaces external vector databases like Pinecone with a self-hosted solution.
 */
class VectorStoreService
{
    /**
     * Store vectors in MySQL database
     * 
     * @param array $vectors Array of vectors with 'id', 'values', and 'metadata'
     * @param string|null $namespace Organization ID for filtering
     * @param string|null $orgId Organization ID (for backward compatibility)
     * @param string|null $documentId Document ID (for tracking)
     * @param string|null $ingestJobId Ingest job ID (for tracking)
     * @return array Empty array for compatibility
     */
    public function upsert(array $vectors, ?string $namespace = null, ?string $orgId = null, ?string $documentId = null, ?string $ingestJobId = null): array
    {
        try {
            $updatedCount = 0;
            
            foreach ($vectors as $vector) {
                // Pack float array into binary BLOB (1536 floats * 4 bytes = 6144 bytes)
                $packed = pack('f*', ...$vector['values']);
                
                // Update the chunk with the embedding
                $updated = DB::table('chunks')
                    ->where('id', $vector['id'])
                    ->update(['embedding' => $packed]);
                
                if ($updated) {
                    $updatedCount++;
                }
            }
            
            Log::info('✅ Vectors stored in MySQL', [
                'total_vectors' => count($vectors),
                'updated_count' => $updatedCount,
                'org_id' => $orgId ?? $namespace,
            ]);
            
            // Note: We no longer track vector upsert costs since storage is now free (local MySQL)
            // The only cost is OpenAI embeddings, which is tracked in EmbeddingService
            
            return [];
        } catch (\Exception $e) {
            Log::error('❌ VectorStore upsert failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Query vectors using cosine similarity
     * 
     * @param array $embedding Query vector (1536 floats)
     * @param int $topK Number of top results to return
     * @param string|null $namespace Organization ID for filtering
     * @param string|null $orgId Organization ID (for backward compatibility)
     * @param string|null $conversationId Conversation ID (for tracking)
     * @param array|null $filter Additional metadata filters (e.g., connector_id)
     * @return array Array of matches with id, score, and metadata
     */
    public function query(array $embedding, int $topK = 6, ?string $namespace = null, ?string $orgId = null, ?string $conversationId = null, ?array $filter = null): array
    {
        try {
            $startTime = microtime(true);
            
            // Use namespace or orgId for filtering
            $filterOrgId = $namespace ?? $orgId;
            
            if (empty($filterOrgId)) {
                Log::warning('⚠️ No organization ID provided for vector query');
                return [];
            }
            
            // Build query to get all chunks with embeddings for this org
            $query = DB::table('chunks')
                ->where('chunks.org_id', $filterOrgId)
                ->whereNotNull('chunks.embedding')
                ->select('chunks.id', 'chunks.document_id', 'chunks.org_id', 'chunks.embedding');
            
            // Apply connector filter if provided (join with documents table)
            if (!empty($filter) && isset($filter['connector_id'])) {
                $connectorIds = $filter['connector_id']['$in'] ?? [$filter['connector_id']];
                
                $query->join('documents', 'chunks.document_id', '=', 'documents.id')
                    ->whereIn('documents.connector_id', $connectorIds);
                
                Log::info('📊 Applying connector filter', ['connector_ids' => $connectorIds]);
            }
            
            $chunks = $query->get();
            
            Log::info('📦 Retrieved chunks for similarity search', [
                'org_id' => $filterOrgId,
                'chunk_count' => count($chunks),
                'filter' => $filter
            ]);
            
            if ($chunks->isEmpty()) {
                Log::warning('⚠️ No chunks with embeddings found', ['org_id' => $filterOrgId]);
                return [];
            }
            
            // Calculate cosine similarity for each chunk
            $scored = [];
            foreach ($chunks as $chunk) {
                // Unpack binary BLOB to float array
                $chunkVector = unpack('f*', $chunk->embedding);
                
                // Calculate cosine similarity
                $score = $this->cosineSimilarity($embedding, array_values($chunkVector));
                
                $scored[] = [
                    'id' => $chunk->id,
                    'score' => $score,
                    'metadata' => [
                        'chunk_id' => $chunk->id,
                        'document_id' => $chunk->document_id,
                        'org_id' => $chunk->org_id,
                    ]
                ];
            }
            
            // Sort by score descending and take top K
            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
            $topResults = array_slice($scored, 0, $topK);
            
            $queryTime = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info('✅ Vector similarity search completed', [
                'org_id' => $filterOrgId,
                'chunks_searched' => count($chunks),
                'top_k' => $topK,
                'query_time_ms' => $queryTime,
                'top_score' => $topResults[0]['score'] ?? 0
            ]);
            
            // Note: We no longer track vector query costs since queries are now free (local MySQL)
            // The only cost is OpenAI embeddings, which is tracked in EmbeddingService
            
            return $topResults;
            
        } catch (\Exception $e) {
            Log::error('❌ VectorStore query failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Vector query failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete vectors from database (soft delete by setting embedding to NULL)
     * 
     * @param array $ids Array of chunk IDs to delete
     * @return array Empty array for compatibility
     */
    public function delete(array $ids): array
    {
        try {
            $deleted = DB::table('chunks')
                ->whereIn('id', $ids)
                ->update(['embedding' => null]);
            
            Log::info('✅ Vectors deleted from MySQL', [
                'chunk_ids' => $ids,
                'deleted_count' => $deleted
            ]);
            
            return [];
        } catch (\Exception $e) {
            Log::error('❌ VectorStore delete failed', [
                'error' => $e->getMessage(),
                'ids' => $ids
            ]);
            return [];
        }
    }

    /**
     * Calculate cosine similarity between two vectors
     * 
     * Cosine similarity = (A · B) / (||A|| * ||B||)
     * Returns value between -1 and 1 (1 = identical, 0 = orthogonal, -1 = opposite)
     * 
     * @param array $a First vector
     * @param array $b Second vector
     * @return float Similarity score
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;
        
        $length = count($a);
        
        for ($i = 0; $i < $length; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $magnitudeA += $a[$i] * $a[$i];
            $magnitudeB += $b[$i] * $b[$i];
        }
        
        // Avoid division by zero
        $denominator = sqrt($magnitudeA) * sqrt($magnitudeB);
        if ($denominator == 0) {
            return 0.0;
        }
        
        return $dotProduct / $denominator;
    }
}
