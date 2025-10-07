<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Connector;
use App\Jobs\IngestConnectorJob;

class TestSync extends Command
{
    protected $signature = 'test:sync {connector_id?}';
    protected $description = 'Test Google Drive sync by triggering ingestion job';

    public function handle()
    {
        $connectorId = $this->argument('connector_id');
        
        if ($connectorId) {
            $connector = Connector::find($connectorId);
        } else {
            $connector = Connector::where('type', 'google_drive')->first();
        }

        if (!$connector) {
            $this->error('No Google Drive connector found');
            return 1;
        }

        $this->info("Found connector: {$connector->type} - {$connector->status}");
        $this->info("Org ID: {$connector->org_id}");

        $this->info('Dispatching ingestion job...');
        IngestConnectorJob::dispatch($connector->id, $connector->org_id);

        $this->info('Job dispatched! Check the logs and database for results.');
        
        return 0;
    }
}
