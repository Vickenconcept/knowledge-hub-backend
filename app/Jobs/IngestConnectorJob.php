<?php

namespace App\Jobs;

use App\Models\Connector;
use App\Models\IngestJob;
use App\Services\GoogleDriveService;
use App\Services\DropboxService;
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

    public function __construct(public string $connectorId, public string $orgId) {}

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
            } elseif ($connector->type === 'dropbox') {
                Log::info('Starting Dropbox file processing...');
                $largeFilesInfo = $this->processDropboxFiles($connector, $tokens, $job, $docs, $chunks, $errors, $processedFiles, $skippedFiles);
                Log::info('Dropbox file processing completed', [
                    'docs' => $docs,
                    'chunks' => $chunks,
                    'errors' => $errors,
                    'processed' => $processedFiles,
                    'skipped' => $skippedFiles,
                    'large_files_pending' => count($largeFilesInfo)
                ]);
            } elseif ($connector->type === 'slack') {
                Log::info('Starting Slack message ingestion...');
                $this->processSlackMessages($connector, $tokens, $job, $docs, $chunks, $errors);
                Log::info('Slack message ingestion completed', [
                    'docs' => $docs,
                    'chunks' => $chunks,
                    'errors' => $errors,
                ]);
            }
            // TODO: Add other connector types here (e.g., Notion)

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

        // Update connector last sync time and status
        $connector->last_synced_at = now();
        $connector->status = 'connected'; // Reset status back to connected
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
        $uploader = new \App\Services\FileUploadService();
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
                // Check if job has been cancelled
                $job->refresh();
                if ($job->status === 'cancelled') {
                    Log::info('🛑 Job cancelled by user, stopping processing', [
                        'job_id' => $job->id,
                        'processed_files' => $processedFiles,
                        'total_files' => count($files)
                    ]);
                    return; // Exit gracefully (void return is fine here)
                }

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

                        if (
                            $googleModifiedTime && $lastFetchedTime &&
                            strtotime($googleModifiedTime) <= strtotime($lastFetchedTime)
                        ) {
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
                    
                    // Track file pull from Google Drive
                    \App\Services\CostTrackingService::trackFilePull(
                        $this->orgId,
                        'google_drive',
                        1,
                        strlen($content),
                        $job->id
                    );

                    // Check if cancelled after download
                    $job->refresh();
                    if ($job->status === 'cancelled') {
                        Log::info('🛑 Job cancelled after download, skipping processing', ['file' => $file->getName()]);
                        return []; // Return empty array for large files
                    }

                    // Upload file to Cloudinary
                    $cloudinaryUrl = null;
                    try {
                        // Save content to temp file for upload
                        $tmpDir = storage_path('app/tmp_uploads');
                        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
                        
                        // Get proper extension based on MIME type
                        $ext = pathinfo($file->getName(), PATHINFO_EXTENSION);
                        if (empty($ext)) {
                            // Map Google MIME types to extensions
                            $ext = match($file->getMimeType()) {
                                'application/vnd.google-apps.document' => 'gdoc',
                                'application/vnd.google-apps.spreadsheet' => 'gsheet',
                                'application/vnd.google-apps.presentation' => 'gslides',
                                'application/pdf' => 'pdf',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                                'text/plain' => 'txt',
                                default => 'dat' // Use .dat instead of .bin
                            };
                        }
                        
                        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . \Illuminate\Support\Str::uuid() . '.' . $ext;
                        file_put_contents($tmpPath, $content);
                        
                        $upload = $uploader->uploadRawPath($tmpPath, 'knowledgehub/google-drive');
                        $cloudinaryUrl = $upload['secure_url'] ?? null;
                        
                        // Clean up temp file
                        @unlink($tmpPath);
                        
                        if ($cloudinaryUrl) {
                            Log::info("✅ File uploaded to Cloudinary", ['url' => $cloudinaryUrl]);
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to upload to Cloudinary: " . $e->getMessage());
                    }

                    Log::info("Extracting text from: " . $file->getName());
                    // Extract text
                    $text = $extractor->extractText($content, $file->getMimeType(), $file->getName());
                    Log::info("Text extracted", ['text_length' => strlen($text)]);

                    // Classify document and extract metadata
                    $classifier = new \App\Services\DocumentClassificationService();
                    $classification = $classifier->classifyDocument($text, $file->getName(), $file->getMimeType());
                    Log::info("Document classified", [
                        'doc_type' => $classification['doc_type'],
                        'tags' => $classification['tags'],
                        'metadata_keys' => array_keys($classification['metadata'])
                    ]);

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
                            'doc_type' => $classification['doc_type'],
                            'metadata' => $classification['metadata'],
                            'tags' => $classification['tags'],
                            'sha256' => $contentHash,
                            'size' => strlen($content),
                            's3_path' => $cloudinaryUrl, // Store Cloudinary URL
                            'fetched_at' => now(),
                        ]);

                        $document = $existingDocument;
                    } else {
                        Log::info("➕ Creating new document record for: " . $file->getName());
                        
                        Log::info("💾 Document URLs being saved:", [
                            'file' => $file->getName(),
                            'source_url' => $file->getWebViewLink(),
                            's3_path (cloudinary)' => $cloudinaryUrl
                        ]);
                        
                        // Create new document record
                        $document = \App\Models\Document::create([
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                    'org_id' => $this->orgId,
                    'connector_id' => $connector->id,
                            'title' => $file->getName(),
                            'source_url' => $file->getWebViewLink(),
                            'mime_type' => $file->getMimeType(),
                            'doc_type' => $classification['doc_type'],
                            'metadata' => $classification['metadata'],
                            'tags' => $classification['tags'],
                            'sha256' => $contentHash,
                            'size' => strlen($content),
                            's3_path' => $cloudinaryUrl, // Store Cloudinary URL
                    'fetched_at' => now(),
                ]);
                        
                        Log::info("✅ Document created in DB", [
                            'doc_id' => $document->id,
                            'saved_source_url' => $document->source_url,
                            'saved_s3_path' => $document->s3_path
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
                        $this->generateAndUploadEmbeddings($createdChunks, $job);
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
                $job->refresh();
                if ($job->status === 'cancelled') {
                    Log::info('🛑 Job cancelled during embedding, stopping immediately', [
                        'job_id' => $job->id,
                        'batch_index' => $batchIndex + 1,
                        'total_batches' => count($batches)
                    ]);
                    return; // Exit embedding process
                }

                Log::info("Processing embedding batch", [
                    'batch' => $batchIndex + 1,
                    'total_batches' => count($batches),
                    'batch_size' => count($batch)
                ]);

                // Extract texts for batch embedding
                $texts = array_map(fn($chunk) => $chunk->text, $batch);

                // Get document ID from first chunk for tracking
                $documentId = $batch[0]->document_id ?? null;

                // Generate embeddings in batch with cost tracking
                $embeddings = $embeddingService->embedBatch($texts, $this->orgId, $documentId, $job->id);

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

                // Upsert to Pinecone with org_id as namespace and cost tracking
                $vectorStore->upsert($vectors, $this->orgId, $this->orgId, $documentId, $job->id);

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

    /**
     * Process Dropbox files - EXACT same logic as Google Drive
     */
    private function processDropboxFiles($connector, $tokens, $job, &$docs, &$chunks, &$errors, &$processedFiles, &$skippedFiles): array
    {
        Log::info('>>> processDropboxFiles started', [
            'connector_id' => $connector->id,
            'has_access_token' => !empty($tokens['access_token'])
        ]);

        $dropbox = new DropboxService($tokens['access_token'], $tokens['refresh_token'] ?? null);
        $extractor = new \App\Services\DocumentExtractionService();
        $uploader = new \App\Services\FileUploadService();
        $largeFiles = []; // Track large files dispatched to separate queue

        if (empty($tokens['access_token'])) {
            Log::error("No access token found for Dropbox connector");
            $errors++;
            return [];
        }

        try {
            Log::info('Fetching file list from Dropbox...');
            // List files from Dropbox
            $files = $dropbox->listFiles('', true);
        } catch (\Exception $e) {
            // Check if it's an expired token error
            if (str_contains($e->getMessage(), 'expired_access_token') || str_contains($e->getMessage(), '401')) {
                Log::info('Dropbox token expired, attempting refresh...');
                
                try {
                    // Refresh the token
                    $newTokens = $dropbox->refreshAccessToken();
                    
                    // Save new tokens
                    $connector->encrypted_tokens = encrypt(json_encode($newTokens));
                    $connector->save();
                    
                    // Retry with new token
                    $dropbox = new DropboxService($newTokens['access_token'], $newTokens['refresh_token'] ?? null);
                    $files = $dropbox->listFiles('', true);
                    
                    Log::info('Dropbox token refreshed and retry successful');
                } catch (\Exception $refreshError) {
                    Log::error('Dropbox token refresh failed: ' . $refreshError->getMessage());
                    $errors++;
                    return [];
                }
            } else {
                throw $e; // Re-throw if not a token issue
            }
        }
        
        try {

            $totalFiles = count($files);

            Log::info("✅ Found " . $totalFiles . " files in Dropbox", [
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
                // Check if job has been cancelled
                $job->refresh();
                if ($job->status === 'cancelled') {
                    Log::info('🛑 Job cancelled by user, stopping processing', [
                        'job_id' => $job->id,
                        'processed_files' => $processedFiles,
                        'total_files' => count($files)
                    ]);
                    return $largeFiles; // Exit gracefully, return large files array
                }

                try {
                    Log::info("Processing file #{$index}", [
                        'name' => $file['name'],
                        'mime_type' => $file['mime_type'],
                        'size' => $file['size'],
                        'id' => $file['id']
                    ]);

                    // Update current file being processed
                    $job->stats = array_merge($job->stats, [
                        'current_file' => $file['name'],
                        'processed_files' => $processedFiles,
                    ]);
                    $job->save();

                    // Defer very large files to separate job (better timeout handling)
                    $fileSize = $file['size'];
                    if ($fileSize > 10 * 1024 * 1024 && $fileSize <= 100 * 1024 * 1024) { // 10MB - 100MB
                        Log::info("📦 Deferring large file to separate job: " . $file['name'], [
                            'size' => $fileSize,
                            'size_mb' => round($fileSize / (1024 * 1024), 2) . ' MB'
                        ]);

                        // Track large file as pending
                        $largeFiles[] = $file['name'];

                        // Dispatch to dedicated large file processing job
                        ProcessLargeFileJob::dispatch(
                            $connector->id,
                            $this->orgId,
                            $job->id, // Pass the IngestJob ID so large file job can update it
                            array_merge($file, ['connector_type' => 'dropbox']),
                            $tokens
                        )->onQueue('large-files');

                        $processedFiles++;
                        continue;
                    } elseif ($fileSize > 100 * 1024 * 1024) { // > 100MB - Skip entirely
                        Log::info("⏭️ Skipping extremely large file: " . $file['name'], [
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
                    $dropboxSourceUrl = 'https://www.dropbox.com/home' . $file['path'];
                    $existingDocument = \App\Models\Document::where('org_id', $this->orgId)
                        ->where('connector_id', $connector->id)
                        ->where('source_url', $dropboxSourceUrl)
                        ->first();

                    // Dropbox provides modified_time in metadata
                    $dropboxModifiedTime = $file['modified_time'];

                    if ($existingDocument && $dropboxModifiedTime) {
                        // Check if file was modified after we last fetched it
                        $lastFetchedTime = $existingDocument->fetched_at;

                        if (
                            $lastFetchedTime &&
                            strtotime($dropboxModifiedTime) <= strtotime($lastFetchedTime)
                        ) {
                            Log::info("⏭️ Document unchanged (by modified time), skipping: " . $file['name'], [
                                'document_id' => $existingDocument->id,
                                'dropbox_modified' => $dropboxModifiedTime,
                                'last_fetched' => $lastFetchedTime,
                                'saved_bandwidth' => round($fileSize / 1024, 2) . ' KB'
                            ]);
                            $skippedFiles++;
                            $processedFiles++;
                            continue; // Skip download and processing entirely!
                        }
                    }

                    Log::info("Fetching file content for: " . $file['name']);
                    // Get file content
                    $content = $dropbox->downloadFile($file['path']);
                    Log::info("File content fetched", ['content_size' => strlen($content)]);
                    
                    // Track file pull from Dropbox
                    \App\Services\CostTrackingService::trackFilePull(
                        $this->orgId,
                        'dropbox',
                        1,
                        strlen($content),
                        $job->id
                    );

                    // Check if cancelled after download
                    $job->refresh();
                    if ($job->status === 'cancelled') {
                        Log::info('🛑 Job cancelled after download, skipping processing', ['file' => $file['name']]);
                        return $largeFiles;
                    }

                    // Upload file to Cloudinary
                    $cloudinaryUrl = null;
                    try {
                        // Save content to temp file for upload
                        $tmpDir = storage_path('app/tmp_uploads');
                        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
                        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin';
                        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . \Illuminate\Support\Str::uuid() . '.' . $ext;
                        file_put_contents($tmpPath, $content);
                        
                        $upload = $uploader->uploadRawPath($tmpPath, 'knowledgehub/dropbox');
                        $cloudinaryUrl = $upload['secure_url'] ?? null;
                        
                        // Clean up temp file
                        @unlink($tmpPath);
                        
                        if ($cloudinaryUrl) {
                            Log::info("✅ File uploaded to Cloudinary", ['url' => $cloudinaryUrl]);
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to upload to Cloudinary: " . $e->getMessage());
                    }

                    Log::info("Extracting text from: " . $file['name']);
                    // Extract text
                    $text = $extractor->extractText($content, $file['mime_type'], $file['name']);
                    Log::info("Text extracted", ['text_length' => strlen($text)]);

                    // Classify document and extract metadata
                    $classifier = new \App\Services\DocumentClassificationService();
                    $classification = $classifier->classifyDocument($text, $file['name'], $file['mime_type']);
                    Log::info("Document classified", [
                        'doc_type' => $classification['doc_type'],
                        'tags' => $classification['tags'],
                        'metadata_keys' => array_keys($classification['metadata'])
                    ]);

                    if (empty(trim($text))) {
                        Log::info("⚠️ No text extracted from: " . $file['name']);
                        $skippedFiles++;
                        $processedFiles++;
                        continue;
                    }

                    $contentHash = hash('sha256', $content);

                    if ($existingDocument) {
                        // Double-check with SHA256 hash after download
                        if ($existingDocument->sha256 === $contentHash) {
                            Log::info("⏭️ Document unchanged (by hash), skipping: " . $file['name'], [
                                'document_id' => $existingDocument->id,
                                'last_fetched' => $existingDocument->fetched_at
                            ]);
                            $skippedFiles++;
                            $processedFiles++;
                            continue; // Skip chunk creation if content hasn't changed
                        }

                        Log::info("🔄 Document changed, updating: " . $file['name'], [
                            'document_id' => $existingDocument->id,
                            'old_hash' => substr($existingDocument->sha256, 0, 8),
                            'new_hash' => substr($contentHash, 0, 8)
                        ]);

                        // Delete old chunks
                        \App\Models\Chunk::where('document_id', $existingDocument->id)->delete();

                        // Update document
                        $existingDocument->update([
                            'title' => $file['name'],
                            'mime_type' => $file['mime_type'],
                            'doc_type' => $classification['doc_type'],
                            'metadata' => $classification['metadata'],
                            'tags' => $classification['tags'],
                            'sha256' => $contentHash,
                            'size' => strlen($content),
                            's3_path' => $cloudinaryUrl, // Store Cloudinary URL
                            'fetched_at' => now(),
                        ]);

                        $document = $existingDocument;
                    } else {
                        Log::info("➕ Creating new document record for: " . $file['name']);
                        
                        Log::info("💾 Document URLs being saved:", [
                            'file' => $file['name'],
                            'source_url' => $dropboxSourceUrl,
                            's3_path (cloudinary)' => $cloudinaryUrl
                        ]);
                        
                        // Create new document record
                        $document = \App\Models\Document::create([
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                            'org_id' => $this->orgId,
                            'connector_id' => $connector->id,
                            'title' => $file['name'],
                            'source_url' => $dropboxSourceUrl,
                            'mime_type' => $file['mime_type'],
                            'doc_type' => $classification['doc_type'],
                            'metadata' => $classification['metadata'],
                            'tags' => $classification['tags'],
                            'sha256' => $contentHash,
                            'size' => strlen($content),
                            's3_path' => $cloudinaryUrl, // Store Cloudinary URL
                            'fetched_at' => now(),
                        ]);
                        
                        Log::info("✅ Document created in DB", [
                            'doc_id' => $document->id,
                            'saved_source_url' => $document->source_url,
                            'saved_s3_path' => $document->s3_path
                        ]);
                    }

                    Log::info("Creating chunks for document: " . $file['name']);
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
                        $this->generateAndUploadEmbeddings($createdChunks, $job);
                    }

                    $docs++;
                    $processedFiles++;

                    Log::info("✅ Processed document: " . $file['name'], [
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
                        'current_file' => $file['name'],
                    ]);
                    $job->save();
                } catch (\Exception $e) {
                    Log::error("❌ Error processing file: {$file['name']}", [
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
            Log::error("Error listing Dropbox files: " . $e->getMessage());
            $errors++;
        }

        // Return large files info for tracking
        return $largeFiles;
    }
    
    /**
     * Process Slack messages - groups into conversations
     */
    private function processSlackMessages($connector, $tokens, $job, &$docs, &$chunks, &$errors): void
    {
        Log::info('Processing Slack workspace', [
            'connector_id' => $connector->id,
            'org_id' => $this->orgId,
        ]);
        
        try {
            $slack = new \App\Services\SlackService();
            
            // Decrypt and get access token
            $accessToken = $tokens['access_token'] ?? null;
            
            if (!$accessToken) {
                throw new \Exception('No access token found for Slack connector');
            }

            // Test auth
            if (!$slack->testAuth($accessToken)) {
                throw new \Exception('Slack authentication failed - token may be expired');
            }

            // Fetch all channels and count total (for progress tracking)
            $cursor = null;
            $channelCount = 0;
            $maxChannelsPerBatch = 10; // Process 10 channels per batch, then take a 10s break
            
            // Get list of channels we've already joined (from connector metadata)
            $joinedChannels = $connector->metadata['joined_channels'] ?? [];
            
            $totalProcessed = 0;
            $batchNumber = 1;
            $allChannels = [];
            
            // First, fetch all channels to count total
            $tempCursor = null;
            do {
                $result = $slack->listChannels($accessToken, $tempCursor);
                $allChannels = array_merge($allChannels, $result['channels']);
                $tempCursor = $result['next_cursor'];
                sleep(2); // Rate limit between list calls
            } while ($tempCursor);
            
            $totalChannels = count(array_filter($allChannels, fn($ch) => !($ch['is_archived'] ?? false)));
            
            // Update job with total channels
            $job->stats = array_merge($job->stats ?? [], [
                'total_channels' => $totalChannels,
                'processed_channels' => 0,
                'current_channel' => 'Starting...',
            ]);
            $job->save();
            
            Log::info('Total Slack channels to process', [
                'total_channels' => $totalChannels,
            ]);
            
            // Now process channels
            $cursor = null;
            do {
                $result = $slack->listChannels($accessToken, $cursor);
                $channels = $result['channels'];
                $cursor = $result['next_cursor'];
                
                // Rate limiting: wait after fetching channel list
                sleep(5);

                foreach ($channels as $channel) {
                    if ($channel['is_archived']) continue;
                    
                    $channelId = $channel['id'];
                    
                    // Limit channels per batch to avoid rate limits
                    if ($channelCount >= $maxChannelsPerBatch) {
                        Log::info('Batch limit reached, taking a break before continuing', [
                            'batch_number' => $batchNumber,
                            'processed_in_batch' => $channelCount,
                            'total_processed' => $totalProcessed,
                            'max_per_batch' => $maxChannelsPerBatch,
                            'cooldown_seconds' => 30,
                        ]);
                        
                        // Reset counter and take a 30-second break before continuing
                        $channelCount = 0;
                        $batchNumber++;
                        sleep(30); // Cooldown period between batches (longer to avoid rate limits)
                        
                        Log::info('Cooldown complete, continuing with next batch', [
                            'next_batch_number' => $batchNumber,
                        ]);
                        // Don't break, continue processing next batch
                    }
                    
                    $channelCount++;
                    $totalProcessed++;
                    
                    // Update job stats with current channel being processed
                    $job->stats = array_merge($job->stats ?? [], [
                        'total_channels' => $totalChannels,
                        'processed_channels' => $totalProcessed - 1, // -1 because we're about to process this one
                        'current_channel' => $channel['name'] ?? 'unknown',
                        'docs' => $docs,
                        'chunks' => $chunks,
                        'errors' => $errors,
                    ]);
                    $job->save();
                    
                    // Try to auto-join public channels (skip if already joined)
                    $isPrivate = $channel['is_private'] ?? false;
                    
                    if (!$isPrivate && !in_array($channelId, $joinedChannels)) {
                        $maxRetries = 3;
                        $attempt = 0;
                        
                        while ($attempt < $maxRetries) {
                            try {
                                $joinResult = $slack->joinChannel($accessToken, $channelId);
                                if ($joinResult['success']) {
                                    Log::info('Bot joined channel', [
                                        'channel_id' => $channelId,
                                        'channel_name' => $channel['name'] ?? 'unknown',
                                    ]);
                                    
                                    // Track this channel as joined
                                    $joinedChannels[] = $channelId;
                                }
                                break; // Success, exit retry loop
                            } catch (\Exception $e) {
                                $attempt++;
                                if (strpos($e->getMessage(), 'ratelimited') !== false) {
                                    $waitTime = pow(2, $attempt) * 5; // Exponential backoff: 10s, 20s, 40s
                                    Log::warning('Rate limited on join, waiting before retry', [
                                        'channel' => $channel['name'] ?? 'unknown',
                                        'attempt' => $attempt,
                                        'wait_seconds' => $waitTime,
                                    ]);
                                    sleep($waitTime);
                                } else {
                                    // Already in channel or other non-critical error, track as joined
                                    if (strpos($e->getMessage(), 'already_in_channel') !== false) {
                                        $joinedChannels[] = $channelId;
                                    }
                                    break;
                                }
                            }
                        }
                        
                        // Rate limiting: wait 10 seconds between joins (very conservative)
                        sleep(10);
                    } elseif (!$isPrivate && in_array($channelId, $joinedChannels)) {
                        Log::debug('Skipping join, already member', [
                            'channel_id' => $channelId,
                            'channel_name' => $channel['name'] ?? 'unknown',
                        ]);
                    }
                    
                    // Process channel messages (skip if rate limited, will retry on next sync)
                    try {
                        $messagesIngested = $this->ingestSlackChannel($slack, $accessToken, $channel, $job, $docs, $chunks, $errors);
                        $docs += $messagesIngested;
                    } catch (\Exception $e) {
                        if (strpos($e->getMessage(), 'ratelimited') !== false) {
                            Log::warning('Rate limited on channel, skipping for now (will retry on next sync)', [
                                'channel' => $channel['name'] ?? 'unknown',
                                'note' => 'This channel will be processed on the next sync',
                            ]);
                            // Don't increment errors, this is expected
                            // Continue to next channel
                        } else {
                            Log::error('Channel processing failed', [
                                'channel' => $channel['name'] ?? 'unknown',
                                'error' => $e->getMessage(),
                            ]);
                            $errors++;
                        }
                    }
                    
                    // Update job stats after processing channel
                    $job->stats = array_merge($job->stats ?? [], [
                        'total_channels' => $totalChannels,
                        'processed_channels' => $totalProcessed,
                        'current_channel' => $channel['name'] ?? 'unknown',
                        'docs' => $docs,
                        'chunks' => $chunks,
                        'errors' => $errors,
                    ]);
                    $job->save();
                    
                    // Rate limiting: wait 15 seconds between channel processing (very conservative)
                    sleep(15);
                }
            } while ($cursor);
            
            // Save updated list of joined channels
            $metadata = $connector->metadata ?? [];
            $metadata['joined_channels'] = array_unique($joinedChannels);
            
            $connector->metadata = $metadata;
            $connector->save();
            
            // Update final stats
            $job->stats = array_merge($job->stats ?? [], [
                'total_channels' => $totalChannels,
                'processed_channels' => $totalProcessed,
                'current_channel' => 'Completed',
                'docs' => $docs,
                'chunks' => $chunks,
                'errors' => $errors,
            ]);
            $job->save();
            
            Log::info('Slack sync completed successfully', [
                'total_channels_joined' => count($metadata['joined_channels']),
                'total_channels_processed' => $totalProcessed,
                'total_batches' => $batchNumber,
            ]);

        } catch (\Exception $e) {
            // Handle rate limiting
            if (strpos($e->getMessage(), 'ratelimited') !== false) {
                Log::warning('Slack rate limited, will retry on next sync', [
                    'error' => $e->getMessage(),
                ]);
            } else {
                Log::error('Slack processing failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            $errors++;
        }
    }
    
    /**
     * Ingest messages from a single Slack channel
     */
    private function ingestSlackChannel($slack, $accessToken, $channel, $job, &$docs, &$chunks, &$errors): int
    {
        $channelId = $channel['id'];
        $channelName = $channel['name'] ?? 'unknown';
        
        Log::info('Processing Slack channel', [
            'channel_id' => $channelId,
            'channel_name' => $channelName,
        ]);

        $allMessages = [];
        $cursor = null;
        
        // Get last sync time for incremental sync
        $lastSyncDoc = \App\Models\Document::where('org_id', $this->orgId)
            ->where('connector_id', $this->connectorId)
            ->where('metadata->channel_id', $channelId)
            ->orderBy('metadata->last_message_at', 'desc')
            ->first();
        
        $oldest = $lastSyncDoc 
            ? ($lastSyncDoc->metadata['last_message_at'] ?? null)
            : null;

        try {
            // Fetch all messages
            do {
                $result = $slack->getChannelHistory(
                    $accessToken,
                    $channelId,
                    $oldest,
                    null,
                    $cursor
                );

                $messages = $result['messages'];
                $cursor = $result['next_cursor'] ?? null;

                foreach ($messages as $message) {
                    // Skip bot messages
                    if ($message['bot_id'] ?? false) {
                        continue;
                    }
                    
                    // Skip certain system subtypes
                    $subtype = $message['subtype'] ?? null;
                    $skipSubtypes = ['channel_join', 'channel_leave', 'channel_archive', 'channel_unarchive'];
                    if ($subtype && in_array($subtype, $skipSubtypes)) {
                        continue;
                    }

                    $allMessages[] = $message;
                }
                
                // Rate limiting: wait between paginated message fetches (conversations.history is Tier 2)
                if ($cursor) {
                    sleep(5);
                }

            } while ($cursor);
            
            // Group messages into conversations
            $conversations = $this->groupSlackMessages($allMessages, $channel);
            
            // Ingest each conversation as a document
            foreach ($conversations as $conversation) {
                $this->ingestSlackConversation($channel, $conversation, $job, $chunks);
            }
            
            return count($conversations);
            
        } catch (\Exception $e) {
            // If bot is not in the channel, skip it
            if (strpos($e->getMessage(), 'not_in_channel') !== false) {
                Log::warning('Bot not in channel, skipping', [
                    'channel_id' => $channelId,
                    'channel_name' => $channelName,
                ]);
                return 0;
            }
            // Re-throw other errors
            throw $e;
        }
    }
    
    /**
     * Group Slack messages into logical conversations
     */
    private function groupSlackMessages(array $messages, array $channel): array
    {
        $conversations = [];
        $threads = [];
        $standalone = [];
        
        // Separate threaded messages from standalone
        foreach ($messages as $message) {
            $threadTs = $message['thread_ts'] ?? null;
            
            if ($threadTs) {
                // This is part of a thread
                if (!isset($threads[$threadTs])) {
                    $threads[$threadTs] = [];
                }
                $threads[$threadTs][] = $message;
            } else {
                $standalone[] = $message;
            }
        }
        
        // Each thread becomes a conversation
        foreach ($threads as $threadTs => $threadMessages) {
            $conversations[] = [
                'type' => 'thread',
                'thread_ts' => $threadTs,
                'messages' => $threadMessages,
            ];
        }
        
        // Group standalone messages by DAY (better conversation continuity)
        // All messages in a channel on the same day form one document
        usort($standalone, function($a, $b) {
            return floatval($a['ts']) - floatval($b['ts']);
        });
        
        $dayGroups = [];
        
        foreach ($standalone as $message) {
            $timestamp = floatval($message['ts']);
            $dateKey = date('Y-m-d', (int)$timestamp); // Group by day
            
            if (!isset($dayGroups[$dateKey])) {
                $dayGroups[$dateKey] = [];
            }
            $dayGroups[$dateKey][] = $message;
        }
        
        // Each day's messages become one conversation
        foreach ($dayGroups as $date => $dayMessages) {
            $conversations[] = [
                'type' => 'daily_conversation',
                'date' => $date,
                'messages' => $dayMessages,
            ];
        }
        
        return $conversations;
    }
    
    /**
     * Ingest a Slack conversation (thread or time-grouped messages)
     */
    private function ingestSlackConversation(array $channel, array $conversation, $job, &$chunks): void
    {
        $channelId = $channel['id'];
        $channelName = $channel['name'] ?? 'unknown';
        $messages = $conversation['messages'];
        
        if (empty($messages)) {
            return;
        }
        
        // Sort messages by timestamp
        usort($messages, function($a, $b) {
            return floatval($a['ts']) <=> floatval($b['ts']);
        });
        
        $firstMessage = $messages[0];
        $lastMessage = $messages[count($messages) - 1];
        
        // Get Slack API access for user resolution
        $connector = \App\Models\Connector::find($this->connectorId);
        $tokens = json_decode(decrypt($connector->encrypted_tokens), true);
        $accessToken = $tokens['access_token'] ?? null;
        $slack = new \App\Services\SlackService();
        
        // Resolve user IDs to names (cache them)
        $userCache = [];
        
        // Extract metadata
        $participants = [];
        $allFiles = [];
        $allReactions = [];
        $messageTexts = [];
        
        foreach ($messages as $message) {
            $userId = $message['user'] ?? 'unknown';
            if ($userId && $userId !== 'unknown') {
                $participants[$userId] = true;
                
                // Resolve user ID to name (with caching)
                if (!isset($userCache[$userId])) {
                    try {
                        $userInfo = $slack->getUserInfo($accessToken, $userId);
                        $userName = $userInfo['real_name'] ?? $userInfo['name'] ?? $userId;
                        $userCache[$userId] = $userName;
                        sleep(1); // Rate limit: 1s between user lookups (Slack Tier 3: 50+/min)
                    } catch (\Exception $e) {
                        $userCache[$userId] = $userId; // Fallback to ID
                    }
                }
            }
            
            // Collect files
            if (!empty($message['files'])) {
                foreach ($message['files'] as $file) {
                    $sharedByName = $userCache[$userId] ?? $userId;
                    
                    // Debug: Log all available URL fields
                    Log::debug("Slack file URLs available", [
                        'file_name' => $file['name'] ?? 'Unknown',
                        'file_id' => $file['id'] ?? '',
                        'url_private' => isset($file['url_private']) ? 'yes' : 'no',
                        'url_private_download' => isset($file['url_private_download']) ? 'yes' : 'no',
                        'permalink' => isset($file['permalink']) ? 'yes' : 'no',
                        'permalink_public' => isset($file['permalink_public']) ? 'yes' : 'no',
                    ]);
                    
                    $allFiles[] = [
                        'id' => $file['id'] ?? '', // File ID for API download
                        'name' => $file['name'] ?? 'Unknown',
                        'type' => $file['mimetype'] ?? 'unknown',
                        'url' => $file['url_private_download'] ?? $file['url_private'] ?? '', // Prefer download URL
                        'shared_by' => $userId,
                        'shared_by_name' => $sharedByName,
                    ];
                }
            }
            
            // Collect reactions
            if (!empty($message['reactions'])) {
                foreach ($message['reactions'] as $reaction) {
                    $allReactions[] = $reaction['name'] ?? '';
                }
            }
            
            // Build message text with attribution (use real name!)
            $text = $message['text'] ?? '';
            
            // Resolve user mentions in text: <@U09KT6PVC92> → @eleazar N
            if (preg_match_all('/<@([A-Z0-9]+)>/', $text, $matches)) {
                foreach ($matches[1] as $mentionedUserId) {
                    // Resolve mentioned user ID to name
                    if (!isset($userCache[$mentionedUserId])) {
                        try {
                            $mentionedUserInfo = $slack->getUserInfo($accessToken, $mentionedUserId);
                            $mentionedUserName = $mentionedUserInfo['real_name'] ?? $mentionedUserInfo['name'] ?? $mentionedUserId;
                            $userCache[$mentionedUserId] = $mentionedUserName;
                            sleep(1); // Rate limit
                        } catch (\Exception $e) {
                            $userCache[$mentionedUserId] = $mentionedUserId;
                        }
                    }
                    
                    // Replace <@USERID> with @Real Name
                    $mentionedName = $userCache[$mentionedUserId] ?? $mentionedUserId;
                    $text = str_replace("<@{$mentionedUserId}>", "@{$mentionedName}", $text);
                }
            }
            
            $timestamp = date('H:i', (int)floatval($message['ts']));
            $userName = $userCache[$userId] ?? $userId;
            $messageTexts[] = "[{$timestamp}] @{$userName}: {$text}";
        }
        
        // Create conversation content
        $content = "Slack Conversation in #{$channelName}\n";
        $content .= "Date: " . date('Y-m-d', (int)floatval($firstMessage['ts'])) . "\n";
        $participantNames = implode(", ", array_map(fn($name) => "@{$name}", array_values($userCache)));
        $content .= "Participants: {$participantNames}\n";
        $content .= str_repeat("=", 50) . "\n\n";
        $content .= implode("\n\n", $messageTexts);
        
        // Add files section
        if (!empty($allFiles)) {
            $content .= "\n\n" . str_repeat("=", 50) . "\n";
            $content .= "[Files Shared in This Conversation]\n";
            foreach ($allFiles as $file) {
                $sharedByName = $file['shared_by_name'] ?? $file['shared_by'];
                $content .= "- {$file['name']} ({$file['type']}) shared by @{$sharedByName}\n";
            }
        }
        
        // Create title
        $isThread = $conversation['type'] === 'thread';
        $messageCount = count($messages);
        
        if ($isThread) {
            $title = "Thread in #{$channelName} ({$messageCount} replies)";
        } else {
            $date = date('M d, Y', (int)floatval($firstMessage['ts']));
            $title = "Conversation in #{$channelName} ({$date}) - {$messageCount} messages";
        }
        
        // Generate unique external ID
        // For threads: use thread_ts
        // For daily conversations: use channel_id + date (so messages on same day merge)
        if ($isThread) {
            $externalId = $conversation['thread_ts'];
        } else {
            $date = $conversation['date'] ?? date('Y-m-d', (int)floatval($firstMessage['ts']));
            $externalId = $channelId . '_' . $date; // e.g., "C09L2H711FT_2025-10-09"
        }
        
        // Permalink to first message
        $permalink = "https://slack.com/archives/{$channelId}/p" . str_replace('.', '', $firstMessage['ts']);
        
        // ========================================
        // SAME AS GOOGLE DRIVE: Classify & Tag
        // ========================================
        $classifier = new \App\Services\DocumentClassificationService();
        $classification = $classifier->classifyDocument($content, $title, 'text/plain');
        
        Log::info("Slack conversation classified", [
            'doc_type' => $classification['doc_type'],
            'tags' => $classification['tags'],
        ]);
        
        // ========================================
        // SAME AS GOOGLE DRIVE: Upload to Cloudinary
        // ========================================
        $cloudinaryUrl = null;
        try {
            $uploader = new \App\Services\FileUploadService();
            // Create temp file with conversation transcript
            $tmpPath = sys_get_temp_dir() . '/' . uniqid('slack_') . '.txt';
            file_put_contents($tmpPath, $content);
            
            $upload = $uploader->uploadRawPath($tmpPath, 'knowledgehub/slack');
            $cloudinaryUrl = $upload['secure_url'] ?? null;
            
            @unlink($tmpPath); // Clean up
            
            Log::info("Slack conversation uploaded to Cloudinary", [
                'url' => $cloudinaryUrl,
            ]);
        } catch (\Exception $e) {
            Log::warning("Could not upload to Cloudinary", [
                'error' => $e->getMessage(),
            ]);
        }
        
        // ========================================
        // Check if conversation document already exists
        // ========================================
        $existingDoc = \App\Models\Document::where('org_id', $this->orgId)
            ->where('connector_id', $this->connectorId)
            ->where('external_id', $externalId)
            ->first();
        
        // If exists, we need to MERGE content, not replace
        if ($existingDoc) {
            // Get existing content and count BEFORE any updates
            $existingContent = $existingDoc->metadata['content'] ?? '';
            $existingMessageCount = $existingDoc->metadata['message_count'] ?? 0;
            $newMessagesText = implode("\n", $messageTexts);
            
            Log::info('Updating existing conversation with new messages', [
                'external_id' => $externalId,
                'existing_messages' => $existingMessageCount,
                'new_messages' => $messageCount,
                'existing_content_length' => strlen($existingContent),
                'new_messages_length' => strlen($newMessagesText),
                'will_merge' => true,
            ]);
            
            // Merge participants
            $existingParticipants = $existingDoc->metadata['participants'] ?? [];
            $mergedParticipants = array_unique(array_merge($existingParticipants, array_keys($participants)));
            
            // Merge files (deduplicate by file ID)
            $existingFiles = $existingDoc->metadata['files'] ?? [];
            $existingFileIds = array_column($existingFiles, 'id');
            
            // Only add new files that don't already exist
            foreach ($allFiles as $file) {
                if (!in_array($file['id'] ?? '', $existingFileIds)) {
                    $existingFiles[] = $file;
                }
            }
            $mergedFiles = $existingFiles;
            
            // Merge user mapping
            $existingUserCache = $existingDoc->metadata['user_mapping'] ?? [];
            $mergedUserCache = array_merge($existingUserCache, $userCache);
            
            // Append ONLY new message texts to existing content (don't rebuild header!)
            
            // Check if existing content has the old "Files Shared" section
            $existingWithoutFiles = $existingContent;
            if (preg_match('/\n\n={50}\n\[Files Shared/', $existingContent, $matches, PREG_OFFSET_CAPTURE)) {
                // Remove old files section
                $existingWithoutFiles = substr($existingContent, 0, $matches[0][1]);
            }
            
            // Append new messages
            $mergedContent = $existingWithoutFiles . "\n" . $newMessagesText;
            
            // Re-add files section if there are any
            if (!empty($mergedFiles)) {
                $mergedContent .= "\n\n" . str_repeat("=", 50) . "\n";
                $mergedContent .= "[Files Shared in This Conversation]\n";
                foreach ($mergedFiles as $file) {
                    $sharedByName = $file['shared_by_name'] ?? $file['shared_by'];
                    $mergedContent .= "- {$file['name']} ({$file['type']}) shared by @{$sharedByName}\n";
                }
            }
            
            // Re-upload merged transcript to Cloudinary
            try {
                $uploader = new \App\Services\FileUploadService();
                $tmpPath = sys_get_temp_dir() . '/' . uniqid('slack_') . '.txt';
                file_put_contents($tmpPath, $mergedContent);
                
                $upload = $uploader->uploadRawPath($tmpPath, 'knowledgehub/slack');
                $cloudinaryUrl = $upload['secure_url'] ?? $cloudinaryUrl;
                
                @unlink($tmpPath);
                
                Log::info("Updated conversation uploaded to Cloudinary", [
                    'url' => $cloudinaryUrl,
                ]);
            } catch (\Exception $e) {
                Log::warning("Could not re-upload updated conversation", [
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Calculate total message count (use stored count from before update)
            $totalMessageCount = $existingMessageCount + $messageCount;
            
            // Update title with total message count
            if ($isThread) {
                $updatedTitle = "Thread in #{$channelName} ({$totalMessageCount} replies)";
            } else {
                $date = date('M d, Y', (int)floatval($firstMessage['ts']));
                $updatedTitle = "Conversation in #{$channelName} ({$date}) - {$totalMessageCount} messages";
            }
            
            // Update existing document
            $existingDoc->update([
                'title' => $updatedTitle,
                'size' => strlen($mergedContent),
                's3_path' => $cloudinaryUrl,
                'sha256' => hash('sha256', $mergedContent),
                'metadata' => array_merge($classification['metadata'], [
                    'channel_id' => $channelId,
                    'channel_name' => $channelName,
                    'conversation_type' => $conversation['type'],
                    'message_count' => $totalMessageCount,
                    'participants' => $mergedParticipants,
                    'participant_count' => count($mergedParticipants),
                    'participant_names' => array_values($mergedUserCache),
                    'user_mapping' => $mergedUserCache,
                    'files' => $mergedFiles,
                    'file_count' => count($mergedFiles),
                    'reactions' => array_unique(array_merge($existingDoc->metadata['reactions'] ?? [], $allReactions)),
                    'first_message_at' => $existingDoc->metadata['first_message_at'],
                    'last_message_at' => floatval($lastMessage['ts']),
                    'permalink' => $permalink,
                    'content' => $mergedContent,
                    'is_thread' => $isThread,
                    'thread_ts' => $isThread ? $conversation['thread_ts'] : null,
                    'download_url' => $cloudinaryUrl,
                    'transcript_url' => $cloudinaryUrl,
                    'is_conversation' => true,
                    'source_platform' => 'slack',
                ]),
                'fetched_at' => now(),
            ]);
            
            Log::info('Document updated with merged content', [
                'document_id' => $existingDoc->id,
                'old_message_count' => $existingMessageCount, // Use stored count, not re-read
                'new_messages_added' => $messageCount,
                'total_messages' => $totalMessageCount,
                'merged_content_length' => strlen($mergedContent),
                'cloudinary_url' => $cloudinaryUrl,
            ]);
            
            $document = $existingDoc;
            
        } else {
            // Create new document
            $document = \App\Models\Document::create([
                'org_id' => $this->orgId,
                'connector_id' => $this->connectorId,
                'external_id' => $externalId,
                'title' => $title,
                'source_url' => $permalink,
                'mime_type' => 'text/plain',
                'doc_type' => $classification['doc_type'],
                'size' => strlen($content),
                'tags' => $classification['tags'],
                's3_path' => $cloudinaryUrl,
                'sha256' => hash('sha256', $content),
                'metadata' => array_merge($classification['metadata'], [
                    'channel_id' => $channelId,
                    'channel_name' => $channelName,
                    'conversation_type' => $conversation['type'],
                    'message_count' => $messageCount,
                    'participants' => array_keys($participants),
                    'participant_count' => count($participants),
                    'participant_names' => array_values($userCache),
                    'user_mapping' => $userCache,
                    'files' => $allFiles,
                    'file_count' => count($allFiles),
                    'reactions' => array_unique($allReactions),
                    'first_message_at' => floatval($firstMessage['ts']),
                    'last_message_at' => floatval($lastMessage['ts']),
                    'permalink' => $permalink,
                    'content' => $content,
                    'is_thread' => $isThread,
                    'thread_ts' => $isThread ? $conversation['thread_ts'] : null,
                    'download_url' => $cloudinaryUrl,
                    'transcript_url' => $cloudinaryUrl,
                    'is_conversation' => true,
                    'source_platform' => 'slack',
                ]),
                'fetched_at' => now(),
            ]);
        }

        Log::info('Conversation indexed', [
            'document_id' => $document->id,
            'type' => $conversation['type'],
            'messages' => $document->metadata['message_count'] ?? $messageCount,
            'participants' => count($document->metadata['participants'] ?? []),
            'files' => count($document->metadata['files'] ?? []),
            'is_update' => !empty($existingDoc),
        ]);

        // If updating existing document, delete old chunks first
        if ($existingDoc) {
            $deletedChunks = \App\Models\Chunk::where('document_id', $document->id)->delete();
            Log::info('Deleted old chunks for re-indexing', [
                'document_id' => $document->id,
                'deleted_chunks' => $deletedChunks,
            ]);
        }

        // Generate embeddings (using same pattern as Google Drive/Dropbox)
        // Use merged content if updating, original content if new
        $conversationContent = $existingDoc 
            ? ($document->metadata['content'] ?? $content)
            : $content;
        
        // Create chunks from conversation content
        $chunkObjects = [];
        $chunkTexts = $this->chunkSlackConversation($conversationContent);
        
        foreach ($chunkTexts as $index => $chunkText) {
            $chunkObj = \App\Models\Chunk::create([
                'document_id' => $document->id,
                'org_id' => $this->orgId,
                'chunk_index' => $index,
                'text' => $chunkText,  // ← Fixed: use 'text' not 'content'
                'token_count' => (int)(str_word_count($chunkText) * 1.3),
            ]);
            $chunkObjects[] = $chunkObj;
            $chunks++; // Increment chunk counter
        }
        
        Log::info('Chunks created for conversation', [
            'document_id' => $document->id,
            'chunk_count' => count($chunkObjects),
            'total_content_length' => strlen($conversationContent),
        ]);
        
        // Use the same embedding method as Google Drive/Dropbox
        if (!empty($chunkObjects)) {
            $this->generateAndUploadEmbeddings($chunkObjects, $job);
        }
        
        // ========================================
        // PROCESS FILES SHARED IN CONVERSATION
        // ========================================
        if (!empty($allFiles)) {
            // Need to pass connector for token access
            $connector = \App\Models\Connector::find($this->connectorId);
            $this->processSlackFiles($allFiles, $connector, $channelName, $document->id, $job, $docs, $chunks, $errors);
        }
    }
    
    /**
     * Process files shared in Slack conversations
     */
    private function processSlackFiles(array $files, $connector, string $channelName, string $conversationDocId, $job, &$docs, &$chunks, &$errors): void
    {
        $extractor = new \App\Services\DocumentExtractionService();
        $uploader = new \App\Services\FileUploadService();
        $classifier = new \App\Services\DocumentClassificationService();
        
        foreach ($files as $fileInfo) {
            try {
                $fileId = $fileInfo['id'] ?? '';
                $fileName = $fileInfo['name'];
                $fileUrl = $fileInfo['url'];
                $mimeType = $fileInfo['type'];
                $sharedBy = $fileInfo['shared_by'];
                
                // Only process text-extractable files
                $processableTypes = [
                    'application/pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
                    'text/plain',
                    'text/csv',
                ];
                
                if (!in_array($mimeType, $processableTypes)) {
                    Log::info("Skipping non-text file from Slack", [
                        'file' => $fileName,
                        'type' => $mimeType,
                    ]);
                    continue;
                }
                
                // ========================================
                // Check for existing document (same as Google Drive/Dropbox)
                // ========================================
                $existingFileDoc = \App\Models\Document::where('org_id', $this->orgId)
                    ->where('connector_id', $this->connectorId)
                    ->where('external_id', 'slack_file_' . $fileId)
                    ->first();
                
                Log::info("Downloading Slack file", [
                    'file' => $fileName,
                    'file_id' => $fileId,
                    'type' => $mimeType,
                    'existing_document' => $existingFileDoc ? 'yes' : 'no',
                ]);
                
                // Get fresh tokens
                $tokens = json_decode(decrypt($connector->encrypted_tokens), true);
                // Use user_token for file operations (xoxp-), fallback to bot token if not available
                $fileAccessToken = $tokens['user_token'] ?? $tokens['access_token'] ?? null;
                
                Log::info("Using token for file download", [
                    'has_user_token' => !empty($tokens['user_token']),
                    'has_bot_token' => !empty($tokens['access_token']),
                    'using_user_token' => !empty($tokens['user_token']),
                ]);
                
                // Step 1: Call files.info API to get fresh download URL
                Log::info("Getting fresh file info from Slack API", [
                    'file_id' => $fileId,
                ]);
                
                $infoResponse = \Illuminate\Support\Facades\Http::withToken($fileAccessToken)
                    ->get('https://slack.com/api/files.info', [
                        'file' => $fileId,
                    ]);
                
                if (!$infoResponse->successful() || !($infoResponse->json()['ok'] ?? false)) {
                    Log::error("Failed to get Slack file info", [
                        'file' => $fileName,
                        'file_id' => $fileId,
                        'error' => $infoResponse->json()['error'] ?? 'unknown',
                    ]);
                    $errors++;
                    continue;
                }
                
                $fileInfo = $infoResponse->json()['file'] ?? null;
                $downloadUrl = $fileInfo['url_private_download'] ?? null;
                
                if (!$downloadUrl) {
                    Log::error("No download URL in Slack file info", [
                        'file' => $fileName,
                        'file_id' => $fileId,
                    ]);
                    $errors++;
                    continue;
                }
                
                // Step 2: Download with proper headers (binary mode)
                Log::info("Downloading file with authenticated request", [
                    'file' => $fileName,
                ]);
                
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                        'Authorization' => 'Bearer ' . $fileAccessToken,
                        'Accept' => 'application/octet-stream',
                    ])
                    ->timeout(120)
                    ->get($downloadUrl);
                
                $fileContent = $response->body();
                $contentType = $response->header('Content-Type');
                
                // Check if we got HTML instead of binary (auth failed)
                if (!$response->successful() || str_starts_with($fileContent, '<') || str_contains($contentType ?? '', 'html')) {
                    Log::warning("Authenticated download failed, trying public URL fallback", [
                        'file' => $fileName,
                        'status' => $response->status(),
                        'content_type' => $contentType,
                    ]);
                    
                    // Step 3: Fallback - Make file public temporarily
                    $publicResponse = \Illuminate\Support\Facades\Http::withToken($fileAccessToken)
                        ->post('https://slack.com/api/files.sharedPublicURL', [
                            'file' => $fileId,
                        ]);
                    
                    if ($publicResponse->json()['ok'] ?? false) {
                        // Get updated file info with public URL
                        $infoResponse = \Illuminate\Support\Facades\Http::withToken($fileAccessToken)
                            ->get('https://slack.com/api/files.info', [
                                'file' => $fileId,
                            ]);
                        
                        $publicUrl = $infoResponse->json()['file']['permalink_public'] ?? null;
                        
                        if ($publicUrl) {
                            Log::info("Downloading via public URL", [
                                'file' => $fileName,
                            ]);
                            
                            $response = \Illuminate\Support\Facades\Http::timeout(120)
                                ->get($publicUrl);
                            
                            $fileContent = $response->body();
                            $contentType = $response->header('Content-Type');
                        }
                    }
                    
                    // Final check
                    if (!$response->successful() || str_starts_with($fileContent, '<') || str_contains($contentType ?? '', 'html')) {
                        Log::error("All Slack download methods failed", [
                            'file' => $fileName,
                            'file_id' => $fileId,
                            'final_status' => $response->status(),
                            'note' => 'File may be deleted, restricted, or token lacks permissions',
                        ]);
                        $errors++;
                        continue;
                    }
                }
                
                Log::info("Slack file downloaded successfully", [
                    'file' => $fileName,
                    'size_bytes' => strlen($fileContent),
                    'content_type' => $contentType,
                ]);
                
                // Check if file content is empty
                if (empty($fileContent)) {
                    Log::error("Downloaded file is empty", [
                        'file' => $fileName,
                        'url' => $fileUrl,
                    ]);
                    $errors++;
                    continue;
                }
                
                // ========================================
                // CHECK IF FILE CHANGED (same as Google Drive/Dropbox)
                // ========================================
                $contentHash = hash('sha256', $fileContent);
                
                if ($existingFileDoc) {
                    // Compare SHA256 hash - if same, skip re-processing
                    if ($existingFileDoc->sha256 === $contentHash) {
                        Log::info("⏭️ Slack file unchanged (by hash), skipping", [
                            'file' => $fileName,
                            'document_id' => $existingFileDoc->id,
                            'last_fetched' => $existingFileDoc->fetched_at,
                        ]);
                        continue; // Skip this file completely
                    }
                    
                    Log::info("🔄 Slack file changed, updating", [
                        'file' => $fileName,
                        'document_id' => $existingFileDoc->id,
                        'old_hash' => substr($existingFileDoc->sha256, 0, 8),
                        'new_hash' => substr($contentHash, 0, 8),
                    ]);
                    
                    // Delete old chunks (will re-create with new content)
                    \App\Models\Chunk::where('document_id', $existingFileDoc->id)->delete();
                }
                
                // Track file pull
                \App\Services\CostTrackingService::trackFilePull(
                    $this->orgId,
                    'slack',
                    1,
                    strlen($fileContent),
                    $job->id
                );
                
                // ========================================
                // UPLOAD ORIGINAL FILE TO CLOUDINARY
                // (Do this BEFORE text extraction, so we keep the file even if extraction fails)
                // ========================================
                $tmpPath = sys_get_temp_dir() . '/' . uniqid('slack_file_') . '_' . basename($fileName);
                $bytesWritten = file_put_contents($tmpPath, $fileContent);
                
                Log::info("Temp file created for Cloudinary upload", [
                    'file' => $fileName,
                    'tmp_path' => $tmpPath,
                    'original_size' => strlen($fileContent),
                    'bytes_written' => $bytesWritten,
                    'file_exists' => file_exists($tmpPath),
                    'actual_size' => file_exists($tmpPath) ? filesize($tmpPath) : 0,
                ]);
                
                $upload = $uploader->uploadRawPath($tmpPath, 'knowledgehub/slack/files');
                $fileCloudinaryUrl = $upload['secure_url'] ?? null;
                
                Log::info("Slack file uploaded to Cloudinary", [
                    'file' => $fileName,
                    'url' => $fileCloudinaryUrl,
                    'upload_response' => $upload,
                ]);
                
                // ========================================
                // NOW EXTRACT TEXT FOR SEARCHING
                // ========================================
                $text = $extractor->extractText($fileContent, $mimeType, $fileName);
                Log::info("Text extracted from Slack file", [
                    'text_length' => strlen($text),
                    'file' => $fileName,
                ]);
                
                // If text extraction failed, use fallback text
                if (empty(trim($text))) {
                    Log::warning("No text extracted from Slack file, using fallback", [
                        'file' => $fileName,
                    ]);
                    $text = "File: {$fileName}\nType: {$mimeType}\nShared in Slack #{$channelName}\nNote: Text extraction failed, but original file is available.";
                }
                
                // Classify document
                $fileClassification = $classifier->classifyDocument($text, $fileName, $mimeType);
                Log::info("Slack file classified", [
                    'file' => $fileName,
                    'doc_type' => $fileClassification['doc_type'],
                    'tags' => $fileClassification['tags'],
                ]);
                
                // Clean up temp file
                @unlink($tmpPath);
                
                // Create or update document for the file (same as Google Drive/Dropbox)
                if ($existingFileDoc) {
                    // Update existing document
                    $existingFileDoc->update([
                        'title' => $fileName,
                        'source_url' => $fileUrl,
                        'mime_type' => $mimeType,
                        'doc_type' => $fileClassification['doc_type'],
                        'size' => strlen($text),
                        'tags' => $fileClassification['tags'],
                        's3_path' => $fileCloudinaryUrl,
                        'sha256' => $contentHash,
                        'metadata' => array_merge($fileClassification['metadata'], [
                            'shared_in_slack' => true,
                            'channel_name' => $channelName,
                            'shared_by' => $sharedBy,
                            'conversation_document_id' => $conversationDocId,
                            'download_url' => $fileCloudinaryUrl,
                            'file_preview_url' => $fileCloudinaryUrl,
                            'original_slack_url' => $fileUrl,
                            'file_extension' => pathinfo($fileName, PATHINFO_EXTENSION),
                            'is_downloadable' => true,
                        ]),
                        'fetched_at' => now(),
                    ]);
                    
                    $fileDocument = $existingFileDoc;
                    
                    Log::info("Slack file document updated", [
                        'document_id' => $fileDocument->id,
                        'file' => $fileName,
                    ]);
                } else {
                    // Create new document
                    $fileDocument = \App\Models\Document::create([
                        'org_id' => $this->orgId,
                        'connector_id' => $this->connectorId,
                        'external_id' => 'slack_file_' . $fileId,
                        'title' => $fileName,
                        'source_url' => $fileUrl,
                        'mime_type' => $mimeType,
                        'doc_type' => $fileClassification['doc_type'],
                        'size' => strlen($text),
                        'tags' => $fileClassification['tags'],
                        's3_path' => $fileCloudinaryUrl,
                        'sha256' => $contentHash,
                        'metadata' => array_merge($fileClassification['metadata'], [
                            'shared_in_slack' => true,
                            'channel_name' => $channelName,
                            'shared_by' => $sharedBy,
                            'conversation_document_id' => $conversationDocId,
                            'download_url' => $fileCloudinaryUrl,
                            'file_preview_url' => $fileCloudinaryUrl,
                            'original_slack_url' => $fileUrl,
                            'file_extension' => pathinfo($fileName, PATHINFO_EXTENSION),
                            'is_downloadable' => true,
                        ]),
                        'fetched_at' => now(),
                    ]);
                    
                    Log::info("Slack file document created", [
                        'document_id' => $fileDocument->id,
                        'file' => $fileName,
                    ]);
                }
                
                // Chunk and embed the file (SAME AS GOOGLE DRIVE!)
                $textChunks = $extractor->chunkText($text);
                Log::info("Slack file chunked", ['chunk_count' => count($textChunks)]);
                
                $createdChunks = [];
                foreach ($textChunks as $index => $chunkText) {
                    $chunk = \App\Models\Chunk::create([
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'org_id' => $this->orgId,
                        'document_id' => $fileDocument->id,
                        'chunk_index' => $index,
                        'text' => $chunkText,
                        'char_start' => $index * 2000,
                        'char_end' => ($index + 1) * 2000,
                        'token_count' => str_word_count($chunkText),
                    ]);
                    $createdChunks[] = $chunk;
                    $chunks++;
                }
                
                if (!empty($createdChunks)) {
                    $this->generateAndUploadEmbeddings($createdChunks, $job);
                }
                
                $docs++;
                
                // Rate limiting: wait between file downloads to avoid overwhelming Slack API
                sleep(3);
                
            } catch (\Exception $e) {
                Log::error("Failed to process Slack file", [
                    'file' => $fileName ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }
    }
    
    /**
     * Chunk Slack conversation content
     */
    private function chunkSlackConversation(string $content): array
    {
        if (empty($content)) {
            return [];
        }
        
        // For short conversations, return as single chunk
        if (strlen($content) < 2000) {
            return [$content];
        }
        
        // For longer conversations, split by message boundaries
        $lines = explode("\n\n", $content);
        $chunks = [];
        $currentChunk = '';
        
        foreach ($lines as $line) {
            if (strlen($currentChunk . $line) > 1500) {
                if ($currentChunk) {
                    $chunks[] = trim($currentChunk);
                }
                $currentChunk = $line . "\n\n";
            } else {
                $currentChunk .= $line . "\n\n";
            }
        }
        
        if ($currentChunk) {
            $chunks[] = trim($currentChunk);
        }
        
        return $chunks;
    }
}
