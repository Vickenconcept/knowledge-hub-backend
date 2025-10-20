<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\ChunkingService;
use App\Services\DocumentExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BatchManualUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param string $orgId
     * @param array<int, array{document_id:string,tmp_path:string,mime:string}> $items
     */
    public function __construct(public string $orgId, public array $items)
    {
    }

    public function handle(DocumentExtractionService $extractor, ChunkingService $chunker): void
    {
        Log::info('=== BatchManualUploadJob STARTED ===', [
            'org_id' => $this->orgId,
            'items' => count($this->items),
        ]);

        foreach ($this->items as $item) {
            $document = Document::where('id', $item['document_id'])
                ->where('org_id', $this->orgId)
                ->first();

            if (!$document) {
                continue;
            }

            try {
                // Use the temporary local path saved during upload for reliable extraction
                $text = $extractor->extractText($item['tmp_path'], $item['mime'], $document->title);

                // Reuse existing single-document pipeline
                CreateChunksJob::dispatch($document->id, $this->orgId, $text);

                // Cleanup tmp file
                @unlink($item['tmp_path']);
            } catch (\Throwable $e) {
                Log::error('BatchManualUploadJob item failed: ' . $e->getMessage(), [
                    'document_id' => $document->id,
                ]);
            }
        }

        Log::info('=== BatchManualUploadJob FINISHED ===', [
            'org_id' => $this->orgId,
            'items' => count($this->items),
        ]);
    }
}


