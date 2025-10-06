<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Organization;
use Illuminate\Support\Facades\Hash;

class TestUserSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Create a test organization
        $org = Organization::create([
            'name' => 'Test Organization',
            'settings' => [],
            'plan' => 'free',
        ]);

        // Create test users
                User::updateOrCreate(
                    ['email' => 'test@example.com'],
                    [
                        'name' => 'Test User',
                        'password' => Hash::make('password123'),
                        'org_id' => $org->id,
                        'role' => 'admin',
                    ]
                );

                User::updateOrCreate(
                    ['email' => 'testuser@gmail.com'],
                    [
                        'name' => 'Test User Gmail',
                        'password' => Hash::make('password123'),
                        'org_id' => $org->id,
                        'role' => 'admin',
                    ]
                );

        $this->command->info('Test users created:');
        $this->command->info('- test@example.com / password123');
        $this->command->info('- testuser@gmail.com / password123');
    }
}