<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Connector;
use App\Models\Organization;
use Illuminate\Support\Str;

class ConnectorSeeder extends Seeder
{
    public function run(): void
    {
        // Get all organizations
        $organizations = Organization::all();

        if ($organizations->isEmpty()) {
            $this->command->info('No organizations found. Please run the TestUserSeeder first.');
            return;
        }

        foreach ($organizations as $organization) {
            // Create default connectors for each organization
            $defaultConnectors = [
                [
                    'type' => 'google_drive',
                    'label' => 'Google Drive',
                    'status' => 'disconnected',
                ],
                [
                    'type' => 'slack',
                    'label' => 'Slack',
                    'status' => 'disconnected',
                ],
                [
                    'type' => 'notion',
                    'label' => 'Notion',
                    'status' => 'disconnected',
                ],
                [
                    'type' => 'dropbox',
                    'label' => 'Dropbox',
                    'status' => 'disconnected',
                ],
                [
                    'type' => 'github',
                    'label' => 'GitHub',
                    'status' => 'disconnected',
                ],
                [
                    'type' => 'confluence',
                    'label' => 'Confluence',
                    'status' => 'disconnected',
                ],
            ];

            foreach ($defaultConnectors as $connectorData) {
                Connector::firstOrCreate(
                    [
                        'org_id' => $organization->id,
                        'type' => $connectorData['type'],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'label' => $connectorData['label'],
                        'status' => $connectorData['status'],
                    ]
                );
            }
        }

        $this->command->info('Default connectors created for all organizations.');
    }
}
