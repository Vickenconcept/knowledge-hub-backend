<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class TestLogin extends Command
{
    protected $signature = 'test:login {email} {password}';
    protected $description = 'Test user login with email and password';

    public function handle()
    {
        $email = $this->argument('email');
        $password = $this->argument('password');

        $this->info("Testing login for: {$email}");

        // Find user
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error("User not found: {$email}");
            return 1;
        }

        $this->info("User found: {$user->name} (ID: {$user->id})");

        // Check password
        $passwordMatch = Hash::check($password, $user->password);
        
        if ($passwordMatch) {
            $this->info("✅ Password matches!");
            return 0;
        } else {
            $this->error("❌ Password does not match!");
            $this->info("Stored hash: " . substr($user->password, 0, 20) . "...");
            return 1;
        }
    }
}
