<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Chunk;
use App\Services\ChunkingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $documentId, public string $orgId, public string $text)
    {
    }

    public function handle(ChunkingService $chunker): void
    {
        $document = Document::find($this->documentId);
        if (!$document) return;

        $splits = $chunker->splitWithOverlap($this->text, 2000, 200);
        $chunkIds = [];
        foreach ($splits as $index => $part) {
            $chunk = Chunk::create([
                'document_id' => $document->id,
                'org_id' => $this->orgId,
                'chunk_index' => $index,
                'text' => $part['text'],
                'char_start' => $part['char_start'],
                'char_end' => $part['char_end'],
                'token_count' => 0,
            ]);
            $chunkIds[] = $chunk->id;
        }

        if (!empty($chunkIds)) {
            EmbedChunksBatchJob::dispatch($chunkIds, $this->orgId);
        }
    }
}


