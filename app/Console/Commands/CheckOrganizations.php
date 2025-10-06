<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Organization;
use App\Models\User;

class CheckOrganizations extends Command
{
    protected $signature = 'check:organizations';
    protected $description = 'Check organizations and users';

    public function handle()
    {
        $this->info('Organizations:');
        Organization::all(['id', 'name'])->each(function($org) {
            $this->line("  {$org->id}: {$org->name}");
        });

        $this->info('Users:');
        User::all(['id', 'name', 'email', 'org_id'])->each(function($user) {
            $this->line("  {$user->id}: {$user->name} ({$user->email}) - Org: {$user->org_id}");
        });

        return 0;
    }
}
