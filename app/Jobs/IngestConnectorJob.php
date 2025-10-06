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

    public function handle(GoogleDriveService $drive, FileUploadService $uploader, DocumentExtractionService $extractor): void
    {
        $connector = Connector::find($this->connectorId);
        if (!$connector) return;

        $job = IngestJob::where('connector_id', $connector->id)
            ->where('org_id', $this->orgId)
            ->orderByDesc('created_at')->first();
        if ($job) {
            $job->status = 'running';
            $job->started_at = now();
            $job->save();
        }

        // TODO: add other providers; for google_drive list recent files
        $files = [];
        $tokens = [];
        if (!empty($connector->encrypted_tokens)) {
            try { $tokens = json_decode(decrypt($connector->encrypted_tokens), true) ?: []; } catch (\Throwable $e) { $tokens = []; }
        }
        if ($connector->type === 'google_drive') {
            $files = $drive->listRecentFiles($tokens, 5);
        }

        $docs = 0; $chunks = 0; $errors = 0;
        try {
            foreach ($files as $file) {
                // download to temp
                $tmp = storage_path('app/tmp_ingest_' . $file['id']);
                if ($connector->type === 'google_drive') {
                    if (!$drive->downloadFile($tokens, $file['id'], $tmp)) continue;
                }
                // upload to Cloudinary
                $upload = $uploader->uploadRawPath($tmp, 'knowledgehub/ingest', $file['title'] ?? null);
                // create doc
                $doc = Document::create([
                    'org_id' => $this->orgId,
                    'connector_id' => $connector->id,
                    'title' => $file['title'] ?? 'Untitled',
                    'source_url' => null,
                    'mime_type' => $file['mime_type'] ?? null,
                    'sha256' => null,
                    'size' => $file['size'] ?? null,
                    's3_path' => $upload['secure_url'] ?? null,
                    'fetched_at' => now(),
                ]);
                // extract and chunk
                $text = $extractor->extractText($tmp, $doc->mime_type);
                CreateChunksJob::dispatch($doc->id, $this->orgId, $text);
                $docs++;
            }
        } catch (\Throwable $e) {
            $errors++;
        }

        if ($job) {
            $job->status = 'completed';
            $job->finished_at = now();
            $job->stats = ['docs' => $docs, 'chunks' => $chunks, 'errors' => $errors];
            $job->save();
        }
    }
}


