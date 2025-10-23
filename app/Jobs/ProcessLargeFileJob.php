<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Chunk;
use App\Services\GoogleDriveService;
use App\Services\DropboxService;
use App\Services\DocumentExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessLargeFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Increase timeout to 2 hours for very large files
    public $timeout = 7200;
    
    // Allow 2 attempts
    public $tries = 2;

    public function __construct(
        public string $connectorId,
        public string $orgId,
        public string $ingestJobId,
        public array $fileData,
        public array $tokens
    ) {
    }

    public function handle(): void
    {
        Log::info('=== ProcessLargeFileJob STARTED ===', [
            'file_name' => $this->fileData['name'],
            'file_size' => $this->fileData['size'],
            'file_id' => $this->fileData['id'],
            'connector_type' => $this->fileData['connector_type'] ?? 'google_drive'
        ]);

        // Check if parent job has been cancelled
        $ingestJob = \App\Models\IngestJob::find($this->ingestJobId);
        if ($ingestJob && $ingestJob->status === 'cancelled') {
            Log::info('🛑 Parent IngestJob cancelled, skipping large file processing', [
                'job_id' => $this->ingestJobId,
                'file' => $this->fileData['name']
            ]);
            return;
        }

        $extractor = new DocumentExtractionService();
        $connectorType = $this->fileData['connector_type'] ?? 'google_drive';

        try {
            // Handle different connector types
            if ($connectorType === 'dropbox') {
                $content = $this->fetchDropboxFile();
            } else {
                // Default: Google Drive
                $content = $this->fetchGoogleDriveFile();
            }
            
            Log::info('Large file content fetched', [
                'size' => strlen($content),
                'size_mb' => round(strlen($content) / (1024 * 1024), 2) . ' MB'
            ]);

            // Extract text
            $text = $extractor->extractText(
                $content,
                $this->fileData['mime_type'],
                $this->fileData['name']
            );

            if (empty(trim($text))) {
                Log::warning('No text extracted from large file', ['name' => $this->fileData['name']]);
                // Still update IngestJob to decrement pending_large_files count
                $this->updateIngestJobStats(0, 0);
                return;
            }

            Log::info('Text extracted from large file', ['text_length' => strlen($text)]);

            $contentHash = hash('sha256', $content);

            // Check for existing document
            $existingDocument = Document::where('org_id', $this->orgId)
                ->where('connector_id', $this->connectorId)
                ->where('source_url', $this->fileData['web_view_link'])
                ->first();

            if ($existingDocument && $existingDocument->sha256 === $contentHash) {
                Log::info('Large file unchanged, skipping', ['name' => $this->fileData['name']]);
                // Still update IngestJob to decrement pending_large_files count
                $this->updateIngestJobStats(0, 0);
                return;
            }

            if ($existingDocument) {
                // Update existing document
                Chunk::where('document_id', $existingDocument->id)->delete();
                $existingDocument->update([
                    'title' => $this->fileData['name'],
                    'mime_type' => $this->fileData['mime_type'],
                    'sha256' => $contentHash,
                    'size' => strlen($content),
                    'fetched_at' => now(),
                ]);
                $document = $existingDocument;
                Log::info('Large file updated', ['name' => $this->fileData['name']]);
            } else {
                // Create new document
                $document = Document::create([
                    'id' => (string) Str::uuid(),
                    'org_id' => $this->orgId,
                    'connector_id' => $this->connectorId,
                    'title' => $this->fileData['name'],
                    'source_url' => $this->fileData['web_view_link'],
                    'mime_type' => $this->fileData['mime_type'],
                    'sha256' => $contentHash,
                    'size' => strlen($content),
                    's3_path' => null,
                    'fetched_at' => now(),
                ]);
                Log::info('Large file document created', ['name' => $this->fileData['name']]);
            }

            // Create chunks
            $textChunks = $extractor->chunkText($text);
            Log::info('Creating chunks for large file', [
                'name' => $this->fileData['name'],
                'chunk_count' => count($textChunks)
            ]);

            $createdChunks = [];
            foreach ($textChunks as $index => $chunkText) {
                $chunk = Chunk::create([
                    'id' => (string) Str::uuid(),
                    'org_id' => $this->orgId,
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'text' => $chunkText,
                    'char_start' => $index * 2000,
                    'char_end' => ($index + 1) * 2000,
                    'token_count' => str_word_count($chunkText),
                ]);
                $createdChunks[] = $chunk;
            }

            // Generate embeddings and upload to Pinecone
            if (!empty($createdChunks)) {
                Log::info('Generating embeddings for large file chunks', [
                    'chunk_count' => count($createdChunks)
                ]);
                $this->generateAndUploadEmbeddings($createdChunks, $ingestJob);
            }

            Log::info('✅ Large file processed successfully', [
                'name' => $this->fileData['name'],
                'chunks_created' => count($textChunks)
            ]);
            
            // Update the parent IngestJob stats
            $this->updateIngestJobStats(count($textChunks), 0);

        } catch (\Exception $e) {
            Log::error('❌ Error processing large file', [
                'name' => $this->fileData['name'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Update parent IngestJob with error
            $this->updateIngestJobStats(0, 1);
            
            throw $e; // Re-throw to mark job as failed
        }
    }
    
    private function updateIngestJobStats(int $chunksCreated, int $errors): void
    {
        try {
            $ingestJob = \App\Models\IngestJob::find($this->ingestJobId);
            if (!$ingestJob) {
                Log::warning('IngestJob not found for update', ['job_id' => $this->ingestJobId]);
                return;
            }
            
            $stats = $ingestJob->stats;
            $stats['docs'] = ($stats['docs'] ?? 0) + ($chunksCreated > 0 ? 1 : 0);
            $stats['chunks'] = ($stats['chunks'] ?? 0) + $chunksCreated;
            $stats['errors'] = ($stats['errors'] ?? 0) + $errors;
            $stats['pending_large_files'] = max(0, ($stats['pending_large_files'] ?? 0) - 1);
            
            // If no more large files pending, mark as completed
            if ($stats['pending_large_files'] === 0) {
                $ingestJob->status = 'completed';
                $ingestJob->finished_at = now();
                $stats['current_file'] = 'Completed';
            } else {
                $stats['current_file'] = "Processing {$stats['pending_large_files']} large file(s) in background...";
            }
            
            $ingestJob->stats = $stats;
            $ingestJob->save();
            
            Log::info('IngestJob stats updated from large file job', [
                'ingest_job_id' => $this->ingestJobId,
                'file_name' => $this->fileData['name'],
                'pending_large_files' => $stats['pending_large_files'],
                'status' => $ingestJob->status
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update IngestJob stats: ' . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessLargeFileJob failed permanently', [
            'file_name' => $this->fileData['name'],
            'error' => $exception->getMessage()
        ]);
        
        // Update parent IngestJob with error
        $this->updateIngestJobStats(0, 1);
    }

    /**
     * Generate embeddings for chunks and upload to Pinecone
     */
    private function generateAndUploadEmbeddings(array $chunks, $job): void
    {
        try {
            $embeddingService = app(\App\Services\EmbeddingService::class);
            $vectorStore = app(\App\Services\VectorStoreService::class);

            // Batch process chunks (100 at a time for OpenAI API limits)
            $batchSize = 100;
            $batches = array_chunk($chunks, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                // Check if job has been cancelled before processing each batch
                if ($job) {
                    $job->refresh();
                    if ($job->status === 'cancelled') {
                        Log::info('🛑 Job cancelled during large file embedding, stopping immediately', [
                            'job_id' => $job->id,
                            'batch_index' => $batchIndex + 1,
                            'total_batches' => count($batches)
                        ]);
                        return; // Exit embedding process
                    }
                }

                Log::info("Processing embedding batch for large file", [
                    'batch' => $batchIndex + 1,
                    'total_batches' => count($batches),
                    'batch_size' => count($batch)
                ]);

                // Extract texts for batch embedding
                $texts = array_map(fn($chunk) => $chunk->text, $batch);
                
                // Generate embeddings in batch
                $embeddings = $embeddingService->embedBatch($texts);

                // Prepare vectors for Pinecone
                $vectors = [];
                foreach ($batch as $idx => $chunk) {
                    $vectors[] = [
                        'id' => (string) $chunk->id,
                        'values' => $embeddings[$idx],
                        'metadata' => [
                            'chunk_id' => $chunk->id,
                            'document_id' => $chunk->document_id,
                            'org_id' => $chunk->org_id,
                            'connector_id' => $this->connectorId,
                            'source_scope' => $chunk->source_scope ?? 'organization',
                            'workspace_name' => $chunk->workspace_name,
                        ]
                    ];
                }

                // Upsert to Pinecone with org_id as namespace
                $vectorStore->upsert($vectors, $this->orgId);

                Log::info("✅ Batch uploaded to Pinecone (large file)", [
                    'batch' => $batchIndex + 1,
                    'vectors_count' => count($vectors)
                ]);
            }

            Log::info("🎉 All embeddings generated for large file", [
                'file' => $this->fileData['name'],
                'total_chunks' => count($chunks),
                'total_batches' => count($batches)
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error generating embeddings for large file: " . $e->getMessage(), [
                'file' => $this->fileData['name'],
                'trace' => $e->getTraceAsString()
            ]);
            // Don't throw - allow processing to complete even if embeddings fail
        }
    }

    /**
     * Fetch file from Google Drive
     */
    private function fetchGoogleDriveFile(): string
    {
        $driveService = new GoogleDriveService();
        
        // Refresh token if needed
        $newToken = $driveService->refreshTokenIfNeeded($this->tokens);
        if ($newToken) {
            $this->tokens = $newToken;
            // Update connector with new token
            $connector = \App\Models\Connector::find($this->connectorId);
            if ($connector) {
                $connector->encrypted_tokens = encrypt(json_encode($newToken));
                $connector->save();
            }
        }

        $driveService->setAccessToken($this->tokens);

        Log::info('Fetching large file from Google Drive', ['name' => $this->fileData['name']]);
        
        return $driveService->getFileContent(
            $this->fileData['id'],
            $this->fileData['mime_type']
        );
    }

    /**
     * Fetch file from Dropbox
     */
    private function fetchDropboxFile(): string
    {
        $dropboxService = new DropboxService(
            $this->tokens['access_token'],
            $this->tokens['refresh_token'] ?? null
        );

        Log::info('Fetching large file from Dropbox', [
            'name' => $this->fileData['name'],
            'path' => $this->fileData['path']
        ]);
        
        return $dropboxService->downloadFile($this->fileData['path']);
    }
}

