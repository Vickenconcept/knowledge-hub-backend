<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * VectorStoreService - MySQL/PostgreSQL BLOB-based Vector Storage
 * 
 * Stores vector embeddings directly in the database using BLOB/BYTEA storage.
 * Performs cosine similarity search in PHP for fast retrieval.
 * 
 * Privacy-first: All vector data stays in your own database, never sent to third parties.
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
            $driver = DB::connection()->getDriverName();
            
            foreach ($vectors as $vector) {
                // Pack float array into binary (1536 floats * 4 bytes = 6144 bytes)
                $packed = pack('f*', ...$vector['values']);
                
                // For PostgreSQL, we need to use bytea hex format
                if ($driver === 'pgsql') {
                    // Convert binary to PostgreSQL hex format: \x + hex string
                    $hexData = '\\x' . bin2hex($packed);
                    
                    // Use raw SQL for PostgreSQL BYTEA
                    $updated = DB::update(
                        'UPDATE chunks SET embedding = ?::bytea WHERE id = ?',
                        [$hexData, $vector['id']]
                    );
                } else {
                    // MySQL BLOB works fine with packed binary
                    $updated = DB::table('chunks')
                        ->where('id', $vector['id'])
                        ->update(['embedding' => $packed]);
                }
                
                if ($updated) {
                    $updatedCount++;
                } else {
                    Log::warning('⚠️ Failed to update chunk embedding', [
                        'chunk_id' => $vector['id'],
                        'driver' => $driver
                    ]);
                }
            }
            
            Log::info('✅ Vectors stored in database', [
                'driver' => $driver,
                'total_vectors' => count($vectors),
                'updated_count' => $updatedCount,
                'org_id' => $orgId ?? $namespace,
            ]);
            
            // If some updates failed, log it
            if ($updatedCount < count($vectors)) {
                Log::warning('⚠️ Some vectors failed to store', [
                    'expected' => count($vectors),
                    'stored' => $updatedCount,
                    'failed' => count($vectors) - $updatedCount
                ]);
            }
            
            // Note: We no longer track vector upsert costs since storage is now free (local DB)
            // The only cost is OpenAI embeddings, which is tracked in EmbeddingService
            
            return [];
        } catch (\Exception $e) {
            Log::error('❌ VectorStore upsert failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'driver' => DB::connection()->getDriverName()
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
            
            // Apply filters (join with documents table for filtering)
            if (!empty($filter)) {
                $query->join('documents', 'chunks.document_id', '=', 'documents.id');
                
                // Apply connector filter
                if (isset($filter['connector_id'])) {
                $connectorIds = $filter['connector_id']['$in'] ?? [$filter['connector_id']];
                
                    // Handle null values (system documents) separately
                    $hasNull = in_array(null, $connectorIds, true);
                    $nonNullIds = array_filter($connectorIds, function($id) { return $id !== null; });
                    
                    // CRITICAL FIX: For organization-scoped documents, we need to include ALL connectors
                    // that have organization-scoped documents, not just the selected ones
                    if ($hasNull && !empty($nonNullIds)) {
                        // Both null and non-null connectors
                        $query->where(function($q) use ($nonNullIds) {
                            $q->whereIn('documents.connector_id', $nonNullIds)
                              ->orWhereNull('documents.connector_id');
                        });
                    } elseif ($hasNull) {
                        // Only null connectors (system documents)
                        $query->whereNull('documents.connector_id');
                    } else {
                        // CRITICAL FIX: For organization documents, include ALL connectors
                        // This ensures organization-scoped documents are accessible from any connector
                        $query->where(function($q) use ($nonNullIds) {
                            $q->whereIn('documents.connector_id', $nonNullIds)
                              ->orWhere(function($subQ) {
                                  $subQ->where('chunks.source_scope', 'organization');
                              });
                        });
                    }
                    
                    Log::info('📊 Applying connector filter', [
                        'connector_ids' => $connectorIds,
                        'has_null' => $hasNull,
                        'non_null_ids' => $nonNullIds
                    ]);
                }
                
                // Apply source scope filter (organization vs personal)
                if (isset($filter['source_scope'])) {
                    $query->where('chunks.source_scope', $filter['source_scope']);
                    Log::info('📊 Applying source scope filter', ['source_scope' => $filter['source_scope']]);
                } elseif (isset($filter['user_id'])) {
                    // For 'both' scope: include organization docs for all + personal docs for this user
                    // CRITICAL FIX: Organization documents should be accessible regardless of connector access
                    $query->where(function($q) use ($filter) {
                        $q->where('chunks.source_scope', 'organization')
                          ->orWhere(function($subQ) use ($filter) {
                              $subQ->where('chunks.source_scope', 'personal')
                                   ->where('documents.user_id', $filter['user_id']);
                          });
                    });
                    Log::info('📊 Applying mixed scope filter', ['user_id' => $filter['user_id']]);
                }
                
                // Apply workspace name filter
                if (isset($filter['workspace_name'])) {
                    $workspaceNames = $filter['workspace_name']['$in'] ?? [$filter['workspace_name']];
                    $query->whereIn('chunks.workspace_name', $workspaceNames);
                    Log::info('📊 Applying workspace name filter', ['workspace_names' => $workspaceNames]);
                }
            }
            
            // Get chunk count first for optimization decision
            $totalChunks = $query->count();
            
            Log::info('📦 Retrieved chunks for similarity search', [
                'org_id' => $filterOrgId,
                'total_chunks' => $totalChunks,
                'filter' => $filter
            ]);
            
            if ($totalChunks === 0) {
                Log::warning('⚠️ No chunks with embeddings found', ['org_id' => $filterOrgId]);
                return [];
            }
            
            // Performance optimization: For large datasets, process in batches to reduce memory usage
            $batchSize = 5000; // Process 5000 chunks at a time
            $scored = [];
            $driver = DB::connection()->getDriverName();
            
            if ($totalChunks > $batchSize) {
                Log::info('⚡ Large dataset detected, processing in batches', [
                    'total_chunks' => $totalChunks,
                    'batch_size' => $batchSize,
                    'estimated_batches' => ceil($totalChunks / $batchSize)
                ]);
                
                // Process in batches to prevent memory exhaustion
                $processedCount = 0;
                for ($offset = 0; $offset < $totalChunks; $offset += $batchSize) {
                    $batchChunks = $query->skip($offset)->take($batchSize)->get();
                    
                    foreach ($batchChunks as $chunk) {
                        // Handle different binary formats for MySQL vs PostgreSQL
                        $binaryData = $chunk->embedding;
                        
                        if ($driver === 'pgsql' && is_resource($binaryData)) {
                            // PostgreSQL returns BYTEA as a stream resource
                            $binaryData = stream_get_contents($binaryData);
                        }
                        
                        // Unpack binary to float array
                        $chunkVector = unpack('f*', $binaryData);
                        
                        if (empty($chunkVector)) {
                            Log::warning('⚠️ Failed to unpack embedding', [
                                'chunk_id' => $chunk->id,
                                'embedding_type' => gettype($chunk->embedding),
                                'driver' => $driver
                            ]);
                            continue;
                        }
                        
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
                    
                    $processedCount += count($batchChunks);
                    
                    // Intermediate logging for large datasets
                    if ($processedCount % 10000 === 0) {
                        Log::info('⚡ Batch processing progress', [
                            'processed' => $processedCount,
                            'total' => $totalChunks,
                            'percent' => round(($processedCount / $totalChunks) * 100, 1)
                        ]);
                    }
                }
                
                Log::info('✅ Batch processing complete', [
                    'total_processed' => $processedCount,
                    'chunks_scored' => count($scored)
                ]);
            } else {
                // Small dataset: process all at once
                $chunks = $query->get();
                
                foreach ($chunks as $chunk) {
                    // Handle different binary formats for MySQL vs PostgreSQL
                    $binaryData = $chunk->embedding;
                    
                    if ($driver === 'pgsql' && is_resource($binaryData)) {
                        // PostgreSQL returns BYTEA as a stream resource
                        $binaryData = stream_get_contents($binaryData);
                    }
                    
                    // Unpack binary to float array
                    $chunkVector = unpack('f*', $binaryData);
                    
                    if (empty($chunkVector)) {
                        Log::warning('⚠️ Failed to unpack embedding', [
                            'chunk_id' => $chunk->id,
                            'embedding_type' => gettype($chunk->embedding),
                            'driver' => $driver
                        ]);
                        continue;
                    }
                    
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
            }
            
            // Sort by score descending and take top K
            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
            $topResults = array_slice($scored, 0, $topK);
            
            $queryTime = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info('✅ Vector similarity search completed', [
                'org_id' => $filterOrgId,
                'chunks_searched' => count($scored),
                'total_chunks' => $totalChunks,
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
