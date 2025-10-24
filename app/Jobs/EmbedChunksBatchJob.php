<?php

namespace App\Jobs;

use App\Models\Chunk;
use App\Services\EmbeddingService;
use App\Services\VectorStoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EmbedChunksBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $chunkIds, public string $orgId)
    {
    }

    public function handle(EmbeddingService $embeddingService, VectorStoreService $vectorStore): void
    {
        $chunks = Chunk::whereIn('id', $this->chunkIds)
            ->with('document:id,connector_id') // Load document to get connector_id
            ->get();
        if ($chunks->isEmpty()) return;

        // Prepare batch inputs preserving order with aligned IDs/metadata
        $texts = [];
        $metas = [];
        foreach ($chunks as $chunk) {
            $texts[] = $chunk->text;
            $metas[] = [
                'id' => (string) $chunk->id,
                'metadata' => [
                    'chunk_id' => (string) $chunk->id,
                    'document_id' => (string) $chunk->document_id,
                    'org_id' => (string) $this->orgId,
                    'connector_id' => $chunk->document ? (string) $chunk->document->connector_id : null,
                    'source_scope' => $chunk->source_scope, // Use the chunk's actual scope
                    'workspace_name' => $chunk->workspace_name,
                    'char_start' => $chunk->char_start,
                    'char_end' => $chunk->char_end,
                ],
            ];
        }

        // Batch embed
        $embeddings = $embeddingService->embedBatch($texts);

        $vectors = [];
        foreach ($embeddings as $i => $vec) {
            $vectors[] = [
                'id' => $metas[$i]['id'],
                'values' => $vec,
                'metadata' => $metas[$i]['metadata'],
            ];
        }

        if (!empty($vectors)) {
            // namespace by org for multi-tenancy
            try {
                $vectorStore->upsert($vectors, $this->orgId);
            } catch (\Throwable $e) {
                // Swallow vector errors so ingestion still succeeds
            }
        }
    }
}


