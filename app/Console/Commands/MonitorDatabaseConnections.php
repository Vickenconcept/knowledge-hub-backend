<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitorDatabaseConnections extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:monitor-connections';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor database connections and log statistics';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Get connection statistics
            $connectionStats = DB::select("
                SELECT 
                    VARIABLE_NAME,
                    VARIABLE_VALUE
                FROM INFORMATION_SCHEMA.GLOBAL_STATUS 
                WHERE VARIABLE_NAME IN (
                    'Threads_connected',
                    'Threads_running',
                    'Max_used_connections',
                    'Max_connections'
                )
            ");

            $stats = [];
            foreach ($connectionStats as $stat) {
                $stats[$stat->VARIABLE_NAME] = $stat->VARIABLE_VALUE;
            }

            // Get current process list
            $processes = DB::select("SHOW PROCESSLIST");
            $activeConnections = count($processes);

            $this->info("Database Connection Statistics:");
            $this->info("Current Connections: {$stats['Threads_connected']}");
            $this->info("Running Threads: {$stats['Threads_running']}");
            $this->info("Max Used Connections: {$stats['Max_used_connections']}");
            $this->info("Max Connections: {$stats['Max_connections']}");
            $this->info("Active Processes: {$activeConnections}");

            // Log to file for monitoring
            Log::info('Database Connection Monitor', [
                'current_connections' => $stats['Threads_connected'],
                'running_threads' => $stats['Threads_running'],
                'max_used_connections' => $stats['Max_used_connections'],
                'max_connections' => $stats['Max_connections'],
                'active_processes' => $activeConnections,
            ]);

            // Alert if connections are high
            $connectionUsage = ($stats['Threads_connected'] / $stats['Max_connections']) * 100;
            if ($connectionUsage > 80) {
                $this->warn("⚠️  High connection usage: {$connectionUsage}%");
                Log::warning('High database connection usage detected', [
                    'usage_percentage' => $connectionUsage,
                    'current_connections' => $stats['Threads_connected'],
                    'max_connections' => $stats['Max_connections'],
                ]);
            }

        } catch (\Exception $e) {
            $this->error("Failed to monitor database connections: " . $e->getMessage());
            Log::error('Database connection monitoring failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
