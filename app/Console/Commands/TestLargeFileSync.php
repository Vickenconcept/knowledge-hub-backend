<?php

namespace App\Console\Commands;

use App\Jobs\IngestConnectorJob;
use App\Models\Connector;
use Illuminate\Console\Command;

class TestLargeFileSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:large-file-sync {--queue=default}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test large file sync with dedicated queue for large files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting Large File Sync Test...');
        $this->newLine();

        // Find the first Google Drive connector
        $connector = Connector::where('type', 'google_drive')
            ->where('status', 'connected')
            ->first();

        if (!$connector) {
            $this->error('❌ No connected Google Drive connector found!');
            $this->info('💡 Please connect Google Drive first from the UI.');
            return 1;
        }

        $this->info("📂 Found connector: {$connector->label}");
        $this->info("🔑 Org ID: {$connector->org_id}");
        $this->newLine();

        // Dispatch the job
        $queue = $this->option('queue');
        IngestConnectorJob::dispatch($connector->id, $connector->org_id)->onQueue($queue);

        $this->info("✅ Ingestion job dispatched to '{$queue}' queue!");
        $this->newLine();

        $this->info('📊 What will happen:');
        $this->line('  • Files < 10MB: Process immediately in main job');
        $this->line('  • Files 10-100MB: Defer to large-files queue (separate job)');
        $this->line('  • Files > 100MB: Skip with log message');
        $this->newLine();

        $this->info('🔧 To process jobs:');
        $this->line('  1. Default queue:     php artisan queue:work --queue=default --timeout=1800');
        $this->line('  2. Large files queue: php artisan queue:work --queue=large-files --timeout=7200');
        $this->newLine();

        $this->info('📝 Monitor logs:');
        $this->line('  tail -f storage/logs/laravel.log');
        $this->newLine();

        $this->info('🎯 Look for these log messages:');
        $this->line('  • "📦 Deferring large file to separate job" - File sent to background');
        $this->line('  • "✅ Large file processed successfully" - Large file completed');
        $this->line('  • "⏭️ Skipping extremely large file" - File > 100MB skipped');
        $this->newLine();

        return 0;
    }
}

