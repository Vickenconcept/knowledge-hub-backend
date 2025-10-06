<?php

namespace App\Jobs;

use App\Models\Connector;
use App\Models\Document;
use App\Services\FileUploadService;
use App\Services\DocumentExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $connectorId, public string $orgId, public array $rawDoc)
    {
    }

    public function handle(FileUploadService $uploader, DocumentExtractionService $extractor): void
    {
        $connector = Connector::find($this->connectorId);
        if (!$connector) return;

        $document = Document::create([
            'org_id' => $this->orgId,
            'connector_id' => $connector->id,
            'title' => $this->rawDoc['title'] ?? 'Untitled',
            'source_url' => $this->rawDoc['source_url'] ?? null,
            'mime_type' => $this->rawDoc['mime_type'] ?? null,
            'sha256' => $this->rawDoc['sha256'] ?? null,
            'size' => $this->rawDoc['size'] ?? null,
            's3_path' => $this->rawDoc['s3_path'] ?? null,
            'fetched_at' => now(),
        ]);

        // If we have a local temp path, upload raw to Cloudinary and store URL
        $localPath = $this->rawDoc['local_path'] ?? null;
        if ($localPath && is_readable($localPath)) {
            $upload = $uploader->uploadRawPath($localPath, 'knowledgehub/raw');
            if (!empty($upload['secure_url'])) {
                $document->s3_path = $upload['secure_url'];
                $document->save();
            }
        }

        // Prefer provided content, else try to extract from local file
        $text = $this->rawDoc['content'] ?? '';
        if (empty($text) && $localPath && is_readable($localPath)) {
            $text = $extractor->extractText($localPath, $document->mime_type);
        }

        CreateChunksJob::dispatch($document->id, $this->orgId, $text);
    }
}


