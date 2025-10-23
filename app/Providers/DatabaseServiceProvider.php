<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Monitor database connections
        DB::listen(function ($query) {
            if (config('app.debug')) {
                Log::debug('Database Query', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time,
                ]);
            }
        });

        // Handle database connection errors
        DB::getEventDispatcher()->listen('Illuminate\Database\Events\QueryException', function ($event) {
            Log::error('Database Query Exception', [
                'sql' => $event->sql,
                'bindings' => $event->bindings,
                'exception' => $event->exception->getMessage(),
            ]);
        });
    }
}
