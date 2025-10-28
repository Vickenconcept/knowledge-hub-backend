<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set default string length for MySQL (prevents index errors)
        Schema::defaultStringLength(191);
        
        // Force utf8mb4 for MySQL connections to handle emojis and special characters
        if (config('database.default') === 'mysql') {
            $this->configureMySQLCharset();
        }

        app()->terminating(function () {
            DB::disconnect();
        });
    }

    /**
     * Configure MySQL to use utf8mb4 charset
     */
    private function configureMySQLCharset(): void
    {
        try {
            DB::statement("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
            DB::statement("SET CHARACTER SET utf8mb4");
            DB::statement("SET character_set_connection=utf8mb4");
        } catch (\Exception $e) {
            // If connection not ready yet, ignore
        }
    }
}
