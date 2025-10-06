<?php

namespace App\Jobs;

use App\Models\Chunk;
use App\Services\VectorStoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $chunkIds)
    {
    }

    public function handle(VectorStoreService $vector): void
    {
        $existing = Chunk::whereIn('id', $this->chunkIds)->pluck('id')->all();
        $stale = array_values(array_diff($this->chunkIds, $existing));
        if (!empty($stale)) {
            try { $vector->delete($stale); } catch (\Throwable $e) { /* ignore */ }
        }
    }
}


