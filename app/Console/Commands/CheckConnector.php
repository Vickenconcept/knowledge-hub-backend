<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Connector;

class CheckConnector extends Command
{
    protected $signature = 'check:connector';
    protected $description = 'Check connector status and tokens';

    public function handle()
    {
        $connector = Connector::first();
        
        if (!$connector) {
            $this->error('No connector found');
            return 1;
        }

        $this->info("Connector Details:");
        $this->info("- ID: {$connector->id}");
        $this->info("- Type: {$connector->type}");
        $this->info("- Label: {$connector->label}");
        $this->info("- Status: {$connector->status}");
        $this->info("- Org ID: {$connector->org_id}");
        $this->info("- Has encrypted tokens: " . (!empty($connector->encrypted_tokens) ? 'Yes' : 'No'));
        $this->info("- Last synced: " . ($connector->last_synced_at ?? 'Never'));

        if (!empty($connector->encrypted_tokens)) {
            try {
                $tokens = json_decode(decrypt($connector->encrypted_tokens), true);
                $this->info("- Access token: " . (isset($tokens['access_token']) ? 'Present' : 'Missing'));
                $this->info("- Refresh token: " . (isset($tokens['refresh_token']) ? 'Present' : 'Missing'));
                $this->info("- Expires: " . (isset($tokens['expires_in']) ? $tokens['expires_in'] : 'Unknown'));
            } catch (\Exception $e) {
                $this->error("- Error decrypting tokens: " . $e->getMessage());
            }
        }

        return 0;
    }
}
