<?php

namespace App\Jobs;

use App\Models\Connector;
use App\Models\IngestJob;
use App\Services\GoogleDriveService;
use App\Services\FileUploadService;
use App\Services\DocumentExtractionService;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        \Log::info('=== IngestConnectorJob STARTED ===', [
            'connector_id' => $this->connectorId,
            'org_id' => $this->orgId
        ]);

        $connector = Connector::find($this->connectorId);
        if (!$connector) {
            \Log::error("Connector not found: {$this->connectorId}");
            return;
        }

        \Log::info('Connector loaded', [
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

        \Log::info('IngestJob status set to running', ['job_id' => $job->id]);

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
                    \Log::info('Tokens decrypted successfully', [
                        'has_access_token' => isset($tokens['access_token']),
                        'has_refresh_token' => isset($tokens['refresh_token'])
                    ]);
                } catch (\Throwable $e) { 
                    \Log::error("Error decrypting tokens: " . $e->getMessage());
                    $tokens = []; 
                }
            } else {
                \Log::warning('No encrypted tokens found for connector', ['connector_id' => $connector->id]);
            }

            if ($connector->type === 'google_drive') {
                \Log::info('Starting Google Drive file processing...');
                $this->processGoogleDriveFiles($connector, $tokens, $job, $docs, $chunks, $errors, $processedFiles, $skippedFiles);
                \Log::info('Google Drive file processing completed', [
                    'docs' => $docs,
                    'chunks' => $chunks,
                    'errors' => $errors,
                    'processed' => $processedFiles,
                    'skipped' => $skippedFiles
                ]);
            }
            // TODO: Add other connector types here (e.g., Slack, Notion)

        } catch (\Throwable $e) {
            \Log::error("Ingestion error: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            $errors++;
        }

        // Update job status
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
        $job->save();

        \Log::info('IngestJob completed', [
            'job_id' => $job->id,
            'status' => 'completed',
            'stats' => $job->stats
        ]);

        // Update connector last sync time
        $connector->last_synced_at = now();
        $connector->save();

        \Log::info('=== IngestConnectorJob FINISHED ===', [
            'connector_id' => $connector->id,
            'total_docs' => $docs,
            'total_chunks' => $chunks,
            'total_errors' => $errors,
            'last_synced_at' => $connector->last_synced_at
        ]);
    }

    private function processGoogleDriveFiles($connector, $tokens, $job, &$docs, &$chunks, &$errors, &$processedFiles, &$skippedFiles)
    {
        \Log::info('>>> processGoogleDriveFiles started', [
            'connector_id' => $connector->id,
            'has_access_token' => !empty($tokens['access_token'])
        ]);

        $driveService = new \App\Services\GoogleDriveService();
        $extractor = new \App\Services\DocumentExtractionService();

        if (empty($tokens['access_token'])) {
            \Log::error("No access token found for Google Drive connector");
            $errors++;
            return;
        }

        \Log::info('Checking and refreshing token if needed');
        
        // Check if token needs refresh and refresh if expired
        try {
            $newToken = $driveService->refreshTokenIfNeeded($tokens);
            
            if ($newToken) {
                // Token was refreshed, save the new token
                \Log::info('Saving refreshed token to database');
                $connector->encrypted_tokens = encrypt(json_encode($newToken));
                $connector->save();
                
                // Use the new token
                $tokens = $newToken;
            }
        } catch (\Exception $e) {
            \Log::error('Token refresh failed: ' . $e->getMessage());
            $errors++;
            return;
        }

        \Log::info('Setting access token for Google Drive service');
        $driveService->setAccessToken($tokens);

        try {
            \Log::info('Fetching file list from Google Drive...');
            // List files from Google Drive
            $results = $driveService->listFiles();
            $files = $results->getFiles();

            $totalFiles = count($files);

            \Log::info("✅ Found " . $totalFiles . " files in Google Drive", [
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
                    \Log::info("Processing file #{$index}", [
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

                    // Skip very large files to prevent timeouts
                    $fileSize = $file->getSize();
                    if ($fileSize > 10 * 1024 * 1024) { // 10MB limit
                        \Log::info("⏭️ Skipping large file: " . $file->getName(), [
                            'size' => $fileSize,
                            'size_mb' => round($fileSize / (1024 * 1024), 2) . ' MB'
                        ]);
                        $skippedFiles++;
                        $processedFiles++;
                        continue;
                    }

                    \Log::info("Fetching file content for: " . $file->getName());
                    // Get file content
                    $content = $driveService->getFileContent($file->getId(), $file->getMimeType());
                    \Log::info("File content fetched", ['content_size' => strlen($content)]);
                    
                    \Log::info("Extracting text from: " . $file->getName());
                    // Extract text
                    $text = $extractor->extractText($content, $file->getMimeType(), $file->getName());
                    \Log::info("Text extracted", ['text_length' => strlen($text)]);
                    
                    if (empty(trim($text))) {
                        \Log::info("⚠️ No text extracted from: " . $file->getName());
                        $skippedFiles++;
                        $processedFiles++;
                        continue;
                    }

                    $contentHash = hash('sha256', $content);

                    // Check if document already exists (by source URL or external ID)
                    $existingDocument = \App\Models\Document::where('org_id', $this->orgId)
                        ->where('connector_id', $connector->id)
                        ->where('source_url', $file->getWebViewLink())
                        ->first();

                    if ($existingDocument) {
                        // Document exists - check if content has changed
                        if ($existingDocument->sha256 === $contentHash) {
                            \Log::info("⏭️ Document unchanged, skipping: " . $file->getName(), [
                                'document_id' => $existingDocument->id,
                                'last_fetched' => $existingDocument->fetched_at
                            ]);
                            $skippedFiles++;
                            $processedFiles++;
                            continue; // Skip processing if content hasn't changed
                        }

                        \Log::info("🔄 Document changed, updating: " . $file->getName(), [
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
                        \Log::info("➕ Creating new document record for: " . $file->getName());
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

                    \Log::info("Creating chunks for document: " . $file->getName());
                    // Create chunks
                    $textChunks = $extractor->chunkText($text);
                    \Log::info("Text chunked", ['chunk_count' => count($textChunks)]);
                    
                    foreach ($textChunks as $index => $chunkText) {
                        \App\Models\Chunk::create([
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                            'org_id' => $this->orgId,
                            'document_id' => $document->id,
                            'chunk_index' => $index,
                            'text' => $chunkText,
                            'char_start' => $index * 2000, // Approximate
                            'char_end' => ($index + 1) * 2000,
                            'token_count' => str_word_count($chunkText), // Rough estimate
                        ]);
                        $chunks++;
                    }

                    $docs++;
                    $processedFiles++;
                    
                    \Log::info("✅ Processed document: " . $file->getName(), [
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
                    \Log::error("❌ Error processing file: {$file->getName()}", [
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
            \Log::error("Error listing Google Drive files: " . $e->getMessage());
            $errors++;
        }
    }
}


