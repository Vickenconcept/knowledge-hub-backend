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

    public function __construct(public string $connectorId, public string $orgId)
    {
    }

    public function handle(): void
    {
        $connector = Connector::find($this->connectorId);
        if (!$connector) {
            \Log::error("Connector not found: {$this->connectorId}");
            return;
        }

        // Create or update ingest job
        $job = IngestJob::create([
            'org_id' => $this->orgId,
            'connector_id' => $connector->id,
            'status' => 'running',
            'stats' => ['docs' => 0, 'chunks' => 0, 'errors' => 0],
        ]);

        $docs = 0; 
        $chunks = 0; 
        $errors = 0;

        try {
            // Get tokens
            $tokens = [];
            if (!empty($connector->encrypted_tokens)) {
                try { 
                    $tokens = json_decode(decrypt($connector->encrypted_tokens), true) ?: []; 
                } catch (\Throwable $e) { 
                    \Log::error("Error decrypting tokens: " . $e->getMessage());
                    $tokens = []; 
                }
            }

            if ($connector->type === 'google_drive') {
                $this->processGoogleDriveFiles($connector, $tokens, $docs, $chunks, $errors);
            }

        } catch (\Throwable $e) {
            \Log::error("Ingestion error: " . $e->getMessage());
            $errors++;
        }

        // Update job status
        $job->status = 'completed';
        $job->finished_at = now();
        $job->stats = ['docs' => $docs, 'chunks' => $chunks, 'errors' => $errors];
        $job->save();

        // Update connector last sync time
        $connector->last_synced_at = now();
        $connector->save();
    }

    private function processGoogleDriveFiles($connector, $tokens, &$docs, &$chunks, &$errors)
    {
        $driveService = new \App\Services\GoogleDriveService();
        $extractor = new \App\Services\DocumentExtractionService();

        if (empty($tokens['access_token'])) {
            \Log::error("No access token found for Google Drive connector");
            $errors++;
            return;
        }

        $driveService->setAccessToken($tokens['access_token']);

        try {
            // List files from Google Drive
            $results = $driveService->listFiles();
            $files = $results->getFiles();

            \Log::info("Found " . count($files) . " files in Google Drive");

            foreach ($files as $file) {
                try {
                    // Skip very large files for now
                    if ($file->getSize() > 50 * 1024 * 1024) { // 50MB limit
                        \Log::info("Skipping large file: " . $file->getName());
                        continue;
                    }

                    // Get file content
                    $content = $driveService->getFileContent($file->getId(), $file->getMimeType());
                    
                    // Extract text
                    $text = $extractor->extractText($content, $file->getMimeType(), $file->getName());
                    
                    if (empty(trim($text))) {
                        \Log::info("No text extracted from: " . $file->getName());
                        continue;
                    }

                    // Create document record
                    $document = \App\Models\Document::create([
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'org_id' => $this->orgId,
                        'connector_id' => $connector->id,
                        'title' => $file->getName(),
                        'source_url' => $file->getWebViewLink(),
                        'mime_type' => $file->getMimeType(),
                        'sha256' => hash('sha256', $content),
                        'size' => strlen($content),
                        's3_path' => null, // We're not storing files in S3 for now
                        'fetched_at' => now(),
                    ]);

                    // Create chunks
                    $textChunks = $extractor->chunkText($text);
                    
                    foreach ($textChunks as $index => $chunkText) {
                        \App\Models\Chunk::create([
                            'id' => (string) \Illuminate\Support\Str::uuid(),
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
                    \Log::info("Processed document: " . $file->getName() . " (" . count($textChunks) . " chunks)");

                } catch (\Exception $e) {
                    \Log::error("Error processing file {$file->getName()}: " . $e->getMessage());
                    $errors++;
                }
            }

        } catch (\Exception $e) {
            \Log::error("Error listing Google Drive files: " . $e->getMessage());
            $errors++;
        }
    }
}


