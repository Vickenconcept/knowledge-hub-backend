<?php

namespace App\Jobs;

use App\Models\Connector;
use App\Models\IngestJob;
use App\Services\GoogleDriveService;
use App\Services\FileUploadService;
use App\Services\DocumentExtractionService;
use App\Models\Document;
use App\Jobs\ProcessLargeFileJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IngestConnectorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Increase timeout to 30 minutes for large files
    public $timeout = 1800;
    
    // Allow 3 attempts before failing
    public $tries = 3;

    public function __construct(public string $connectorId, public string $orgId)
    {
    }

    public function handle(): void
    {
        Log::info('=== IngestConnectorJob STARTED ===', [
            'connector_id' => $this->connectorId,
            'org_id' => $this->orgId
        ]);

        $connector = Connector::find($this->connectorId);
        if (!$connector) {
            Log::error("Connector not found: {$this->connectorId}");
            return;
        }

        Log::info('Connector loaded', [
            'id' => $connector->id,
            'type' => $connector->type,
            'status' => $connector->status,
            'has_tokens' => !empty($connector->encrypted_tokens)
        ]);

        // Create or update ingest job
        $job = IngestJob::create([
            'org_id' => $this->orgId,
            'connector_id' => $connector->id,
            'status' => 'running',
            'stats' => [
                'docs' => 0, 
                'chunks' => 0, 
                'errors' => 0,
                'total_files' => 0,
                'processed_files' => 0,
                'skipped_files' => 0,
                'current_file' => null,
            ],
        ]);

        Log::info('IngestJob status set to running', ['job_id' => $job->id]);

        $docs = 0; 
        $chunks = 0; 
        $errors = 0;
        $processedFiles = 0;
        $skippedFiles = 0;

        try {
            // Get tokens
        $tokens = [];
        if (!empty($connector->encrypted_tokens)) {
                try { 
                    $tokens = json_decode(decrypt($connector->encrypted_tokens), true) ?: []; 
                    Log::info('Tokens decrypted successfully', [
                        'has_access_token' => isset($tokens['access_token']),
                        'has_refresh_token' => isset($tokens['refresh_token'])
                    ]);
                } catch (\Throwable $e) { 
                    Log::error("Error decrypting tokens: " . $e->getMessage());
                    $tokens = []; 
                }
            } else {
                Log::warning('No encrypted tokens found for connector', ['connector_id' => $connector->id]);
            }

        if ($connector->type === 'google_drive') {
                        Log::info('Starting Google Drive file processing...');
                        $largeFilesInfo = $this->processGoogleDriveFiles($connector, $tokens, $job, $docs, $chunks, $errors, $processedFiles, $skippedFiles);
                        Log::info('Google Drive file processing completed', [
                            'docs' => $docs,
                            'chunks' => $chunks,
                            'errors' => $errors,
                            'processed' => $processedFiles,
                            'skipped' => $skippedFiles,
                            'large_files_pending' => count($largeFilesInfo)
                        ]);
                    }
            // TODO: Add other connector types here (e.g., Slack, Notion)

        } catch (\Throwable $e) {
            Log::error("Ingestion error: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            $errors++;
        }

        // Update job status
        $pendingLargeFiles = count($largeFilesInfo ?? []);
        
        if ($pendingLargeFiles > 0) {
            // If there are large files still processing, mark as 'processing_large_files'
            $job->status = 'processing_large_files';
            $job->stats = [
                'docs' => $docs, 
                'chunks' => $chunks, 
                'errors' => $errors,
                'total_files' => $job->stats['total_files'] ?? 0,
                'processed_files' => $processedFiles,
                'skipped_files' => $skippedFiles,
                'pending_large_files' => $pendingLargeFiles,
                'large_files' => $largeFilesInfo,
                'current_file' => "Processing {$pendingLargeFiles} large file(s) in background...",
            ];
        } else {
            // All files completed (no large files)
            $job->status = 'completed';
            $job->finished_at = now();
            $job->stats = [
                'docs' => $docs, 
                'chunks' => $chunks, 
                'errors' => $errors,
                'total_files' => $job->stats['total_files'] ?? 0,
                'processed_files' => $processedFiles,
                'skipped_files' => $skippedFiles,
                'current_file' => 'Completed',
            ];
        }
        $job->save();

        Log::info('IngestJob completed', [
            'job_id' => $job->id,
            'status' => $job->status,  // Use actual status (completed or processing_large_files)
            'stats' => $job->stats
        ]);

        // Update connector last sync time
        $connector->last_synced_at = now();
        $connector->save();

        Log::info('=== IngestConnectorJob FINISHED ===', [
            'connector_id' => $connector->id,
            'total_docs' => $docs,
            'total_chunks' => $chunks,
            'total_errors' => $errors,
            'last_synced_at' => $connector->last_synced_at
        ]);
    }

    private function processGoogleDriveFiles($connector, $tokens, $job, &$docs, &$chunks, &$errors, &$processedFiles, &$skippedFiles)
    {
        Log::info('>>> processGoogleDriveFiles started', [
            'connector_id' => $connector->id,
            'has_access_token' => !empty($tokens['access_token'])
        ]);

        $driveService = new \App\Services\GoogleDriveService();
        $extractor = new \App\Services\DocumentExtractionService();
        $largeFiles = []; // Track large files dispatched to separate queue

        if (empty($tokens['access_token'])) {
            Log::error("No access token found for Google Drive connector");
            $errors++;
            return;
        }

        Log::info('Checking and refreshing token if needed');
        
        // Check if token needs refresh and refresh if expired
        try {
            $newToken = $driveService->refreshTokenIfNeeded($tokens);
            
            if ($newToken) {
                // Token was refreshed, save the new token
                Log::info('Saving refreshed token to database');
                $connector->encrypted_tokens = encrypt(json_encode($newToken));
                $connector->save();
                
                // Use the new token
                $tokens = $newToken;
            }
        } catch (\Exception $e) {
            Log::error('Token refresh failed: ' . $e->getMessage());
            $errors++;
            return;
        }

        Log::info('Setting access token for Google Drive service');
        $driveService->setAccessToken($tokens);

        try {
            Log::info('Fetching file list from Google Drive...');
            // List files from Google Drive
            $results = $driveService->listFiles();
            $files = $results->getFiles();

            $totalFiles = count($files);

            Log::info("✅ Found " . $totalFiles . " files in Google Drive", [
                'file_count' => $totalFiles
            ]);

            // Update job with total files count
            $job->stats = array_merge($job->stats, [
                'total_files' => $totalFiles,
                'processed_files' => 0,
                'skipped_files' => 0,
            ]);
            $job->save();

            foreach ($files as $index => $file) {
                try {
                    Log::info("Processing file #{$index}", [
                        'name' => $file->getName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'id' => $file->getId()
                    ]);

                    // Update current file being processed
                    $job->stats = array_merge($job->stats, [
                        'current_file' => $file->getName(),
                        'processed_files' => $processedFiles,
                    ]);
                    $job->save();

                    // Defer very large files to separate job (better timeout handling)
                    $fileSize = $file->getSize();
                    if ($fileSize > 10 * 1024 * 1024 && $fileSize <= 100 * 1024 * 1024) { // 10MB - 100MB
                        Log::info("📦 Deferring large file to separate job: " . $file->getName(), [
                            'size' => $fileSize,
                            'size_mb' => round($fileSize / (1024 * 1024), 2) . ' MB'
                        ]);
                        
                        // Track large file as pending
                        $largeFiles[] = $file->getName();
                        
                        // Dispatch to dedicated large file processing job
                        ProcessLargeFileJob::dispatch(
                            $connector->id,
                            $this->orgId,
                            $job->id, // Pass the IngestJob ID so large file job can update it
                            [
                                'id' => $file->getId(),
                                'name' => $file->getName(),
                                'mime_type' => $file->getMimeType(),
                                'size' => $fileSize,
                                'web_view_link' => $file->getWebViewLink(),
                            ],
                            $tokens
                        )->onQueue('large-files');
                        
                        $processedFiles++;
                        continue;
                    } elseif ($fileSize > 100 * 1024 * 1024) { // > 100MB - Skip entirely
                        Log::info("⏭️ Skipping extremely large file: " . $file->getName(), [
                            'size' => $fileSize,
                            'size_mb' => round($fileSize / (1024 * 1024), 2) . ' MB',
                            'reason' => 'File too large (>100MB) - consider using cloud processing service'
                        ]);
                        $skippedFiles++;
                        $processedFiles++;
                        continue;
                    }

                    // OPTIMIZATION: Check if document exists BEFORE downloading
                    // This saves bandwidth and processing time for unchanged files
                    $existingDocument = \App\Models\Document::where('org_id', $this->orgId)
                        ->where('connector_id', $connector->id)
                        ->where('source_url', $file->getWebViewLink())
                        ->first();

                    // Google Drive provides MD5 hash in metadata (if available)
                    $googleMd5 = $file->getMd5Checksum(); // May be null for some file types
                    
                    if ($existingDocument && $googleMd5) {
                        // If we have an MD5 from Google Drive, use it for quick comparison
                        // Convert our SHA256 to MD5 for comparison (or store MD5 in DB)
                        // For now, we'll use a simpler approach: check modified time
                        $googleModifiedTime = $file->getModifiedTime();
                        $lastFetchedTime = $existingDocument->fetched_at;
                        
                        if ($googleModifiedTime && $lastFetchedTime && 
                            strtotime($googleModifiedTime) <= strtotime($lastFetchedTime)) {
                            Log::info("⏭️ Document unchanged (by modified time), skipping: " . $file->getName(), [
                                'document_id' => $existingDocument->id,
                                'google_modified' => $googleModifiedTime,
                                'last_fetched' => $lastFetchedTime,
                                'saved_bandwidth' => round($fileSize / 1024, 2) . ' KB'
                            ]);
                            $skippedFiles++;
                            $processedFiles++;
                            continue; // Skip download and processing entirely!
                        }
                    }

                    Log::info("Fetching file content for: " . $file->getName());
                    // Get file content
                    $content = $driveService->getFileContent($file->getId(), $file->getMimeType());
                    Log::info("File content fetched", ['content_size' => strlen($content)]);
                    
                    Log::info("Extracting text from: " . $file->getName());
                    // Extract text
                    $text = $extractor->extractText($content, $file->getMimeType(), $file->getName());
                    Log::info("Text extracted", ['text_length' => strlen($text)]);
                    
                    if (empty(trim($text))) {
                        Log::info("⚠️ No text extracted from: " . $file->getName());
                        $skippedFiles++;
                        $processedFiles++;
                        continue;
                    }

                    $contentHash = hash('sha256', $content);

                    if ($existingDocument) {
                        // Double-check with SHA256 hash after download
                        if ($existingDocument->sha256 === $contentHash) {
                            Log::info("⏭️ Document unchanged (by hash), skipping: " . $file->getName(), [
                                'document_id' => $existingDocument->id,
                                'last_fetched' => $existingDocument->fetched_at
                            ]);
                            $skippedFiles++;
                            $processedFiles++;
                            continue; // Skip chunk creation if content hasn't changed
                        }

                        Log::info("🔄 Document changed, updating: " . $file->getName(), [
                            'document_id' => $existingDocument->id,
                            'old_hash' => substr($existingDocument->sha256, 0, 8),
                            'new_hash' => substr($contentHash, 0, 8)
                        ]);

                        // Delete old chunks
                        \App\Models\Chunk::where('document_id', $existingDocument->id)->delete();
                        
                        // Update document
                        $existingDocument->update([
                            'title' => $file->getName(),
                            'mime_type' => $file->getMimeType(),
                            'sha256' => $contentHash,
                            'size' => strlen($content),
                            'fetched_at' => now(),
                        ]);

                        $document = $existingDocument;
                    } else {
                        Log::info("➕ Creating new document record for: " . $file->getName());
                        // Create new document record
                        $document = \App\Models\Document::create([
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                    'org_id' => $this->orgId,
                    'connector_id' => $connector->id,
                            'title' => $file->getName(),
                            'source_url' => $file->getWebViewLink(),
                            'mime_type' => $file->getMimeType(),
                            'sha256' => $contentHash,
                            'size' => strlen($content),
                            's3_path' => null, // We're not storing files in S3 for now
                    'fetched_at' => now(),
                ]);
                    }

                    Log::info("Creating chunks for document: " . $file->getName());
                    // Create chunks
                    $textChunks = $extractor->chunkText($text);
                    Log::info("Text chunked", ['chunk_count' => count($textChunks)]);
                    
                    $createdChunks = [];
                    foreach ($textChunks as $index => $chunkText) {
                        $chunk = \App\Models\Chunk::create([
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                            'org_id' => $this->orgId,
                            'document_id' => $document->id,
                            'chunk_index' => $index,
                            'text' => $chunkText,
                            'char_start' => $index * 2000, // Approximate
                            'char_end' => ($index + 1) * 2000,
                            'token_count' => str_word_count($chunkText), // Rough estimate
                        ]);
                        $createdChunks[] = $chunk;
                        $chunks++;
                    }

                    // Generate embeddings and upload to Pinecone
                    if (!empty($createdChunks)) {
                        Log::info("Generating embeddings for chunks", ['chunk_count' => count($createdChunks)]);
                        $this->generateAndUploadEmbeddings($createdChunks);
                    }

                $docs++;
                    $processedFiles++;
                    
                    Log::info("✅ Processed document: " . $file->getName(), [
                        'chunks_created' => count($textChunks),
                        'total_docs' => $docs,
                        'total_chunks' => $chunks,
                        'progress' => round(($processedFiles / $totalFiles) * 100, 1) . '%'
                    ]);

                    // Update job progress every file
                    $job->stats = array_merge($job->stats, [
                        'docs' => $docs,
                        'chunks' => $chunks,
                        'errors' => $errors,
                        'processed_files' => $processedFiles,
                        'skipped_files' => $skippedFiles,
                        'current_file' => $file->getName(),
                    ]);
            $job->save();

                } catch (\Exception $e) {
                    Log::error("❌ Error processing file: {$file->getName()}", [
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errors++;
                    $processedFiles++;
                    $skippedFiles++;
                }
            }

        } catch (\Exception $e) {
            Log::error("Error listing Google Drive files: " . $e->getMessage());
            $errors++;
        }

        // Return large files info for tracking
        return $largeFiles;
    }

    /**
     * Generate embeddings for chunks and upload to Pinecone
     */
    private function generateAndUploadEmbeddings(array $chunks): void
    {
        try {
            $embeddingService = app(\App\Services\EmbeddingService::class);
            $vectorStore = app(\App\Services\VectorStoreService::class);

            // Batch process chunks (100 at a time for OpenAI API limits)
            $batchSize = 100;
            $batches = array_chunk($chunks, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                Log::info("Processing embedding batch", [
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
                        ]
                    ];
                }

                // Upsert to Pinecone with org_id as namespace
                $vectorStore->upsert($vectors, $this->orgId);

                Log::info("✅ Batch uploaded to Pinecone", [
                    'batch' => $batchIndex + 1,
                    'vectors_count' => count($vectors)
                ]);
            }

            Log::info("🎉 All embeddings generated and uploaded", [
                'total_chunks' => count($chunks),
                'total_batches' => count($batches)
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error generating embeddings: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            // Don't throw - allow ingestion to continue even if embeddings fail
        }
    }
}


