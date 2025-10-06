<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Chunk;
use App\Services\DocumentExtractionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReindexDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $documentId, public string $orgId)
    {
    }

    public function handle(DocumentExtractionService $extractor): void
    {
        $doc = Document::find($this->documentId);
        if (!$doc) return;

        // Try to fetch file back from Cloudinary raw URL if available
        $text = '';
        if (!empty($doc->s3_path)) {
            try {
                $tmp = storage_path('app/tmp_reindex_' . $doc->id);
                $resp = Http::get($doc->s3_path);
                if ($resp->successful()) {
                    @file_put_contents($tmp, $resp->body());
                    $text = $extractor->extractText($tmp, $doc->mime_type);
                }
            } catch (\Throwable $e) { /* ignore */ }
        }
        if ($text === '') {
            // fallback: concatenate existing chunks
            $text = Chunk::where('document_id', $doc->id)->orderBy('chunk_index')->pluck('text')->implode(' ');
        }

        // Recreate chunks and embeddings
        Chunk::where('document_id', $doc->id)->delete();
        CreateChunksJob::dispatch($doc->id, $this->orgId, $text);
    }
}


