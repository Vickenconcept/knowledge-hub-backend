<?php

namespace App\Console\Commands;

use App\Models\Chunk;
use App\Models\Connector;
use App\Services\VectorStoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupOrphanedVectors extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vectors:cleanup-orphaned 
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--org= : Limit to specific organization ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up orphaned chunks and vectors from deleted connectors';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $orgId = $this->option('org');

        $this->info('🔍 Scanning for orphaned chunks (from deleted connectors)...');

        // Find chunks whose documents belong to deleted connectors
        $query = Chunk::query()
            ->join('documents', 'chunks.document_id', '=', 'documents.id')
            ->leftJoin('connectors', 'documents.connector_id', '=', 'connectors.id')
            ->whereNull('connectors.id'); // Connector has been deleted

        if ($orgId) {
            $query->where('chunks.org_id', $orgId);
            $this->info("Filtering by org_id: {$orgId}");
        }

        $orphanedChunks = $query->select('chunks.*')->get();

        if ($orphanedChunks->isEmpty()) {
            $this->info('✅ No orphaned chunks found!');
            return 0;
        }

        $this->warn("Found {$orphanedChunks->count()} orphaned chunks");

        // Group by document for reporting
        $documentGroups = $orphanedChunks->groupBy('document_id');
        
        $this->table(
            ['Document ID', 'Chunks', 'Org ID'],
            $documentGroups->map(function ($chunks, $docId) {
                return [
                    substr($docId, 0, 20) . '...',
                    $chunks->count(),
                    $chunks->first()->org_id ?? 'N/A',
                ];
            })->toArray()
        );

        if ($isDryRun) {
            $this->info('🔍 DRY RUN - No changes made');
            $this->info("Would delete {$orphanedChunks->count()} chunks from database");
            return 0;
        }

        if (!$this->confirm('Do you want to delete these orphaned chunks?')) {
            $this->info('Cancelled');
            return 0;
        }

        $chunkIds = $orphanedChunks->pluck('id')->toArray();

        // Delete vectors from database first
        $this->info('🗑️  Deleting vectors from database...');
        $vectorStore = new VectorStoreService();
        
        try {
            $vectorStore->delete($chunkIds);
            $this->info("✅ Deleted {$orphanedChunks->count()} vectors from database");
        } catch (\Exception $e) {
            $this->error('❌ Failed to delete from database: ' . $e->getMessage());
            if (!$this->confirm('Continue with database deletion anyway?')) {
                return 1;
            }
        }

        // Delete from database
        $this->info('🗑️  Deleting chunks from database...');
        $deleted = Chunk::whereIn('id', $chunkIds)->delete();
        $this->info("✅ Deleted {$deleted} chunks from database");

        // Also delete orphaned documents (optional)
        if ($this->confirm('Also delete orphaned documents (with no connector)?')) {
            $orphanedDocs = \App\Models\Document::query()
                ->leftJoin('connectors', 'documents.connector_id', '=', 'connectors.id')
                ->whereNull('connectors.id')
                ->select('documents.*')
                ->get();

            if ($orphanedDocs->isEmpty()) {
                $this->info('No orphaned documents found');
            } else {
                $deletedDocs = \App\Models\Document::whereIn('id', $orphanedDocs->pluck('id'))->delete();
                $this->info("✅ Deleted {$deletedDocs} orphaned documents");
            }
        }

        Log::info('Orphaned vectors cleanup completed', [
            'chunks_deleted' => $deleted,
            'org_id' => $orgId,
            'dry_run' => false,
        ]);

        $this->info('🎉 Cleanup completed successfully!');
        return 0;
    }
}

