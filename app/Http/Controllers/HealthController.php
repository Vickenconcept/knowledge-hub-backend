<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use App\Models\IngestJob;

class HealthController extends Controller
{
    /**
     * Basic health check
     */
    public function health()
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'service' => 'KHub API',
            'version' => config('app.version', '1.0.0'),
        ]);
    }

    /**
     * Detailed health check with dependencies
     */
    public function ready()
    {
        $checks = [];
        $overallStatus = 'ok';

        // Database check
        try {
            DB::connection()->getPdo();
            $checks['database'] = [
                'status' => 'ok',
                'message' => 'Database connection successful'
            ];
        } catch (\Exception $e) {
            $checks['database'] = [
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage()
            ];
            $overallStatus = 'error';
        }

        // Cache check (Redis)
        try {
            Cache::put('health_check', 'ok', 10);
            $cacheValue = Cache::get('health_check');
            if ($cacheValue === 'ok') {
                $checks['cache'] = [
                    'status' => 'ok',
                    'message' => 'Cache (Redis) is working'
                ];
            } else {
                throw new \Exception('Cache test failed');
            }
        } catch (\Exception $e) {
            $checks['cache'] = [
                'status' => 'error',
                'message' => 'Cache (Redis) failed: ' . $e->getMessage()
            ];
            $overallStatus = 'error';
        }

        // Queue check
        try {
            $queueSize = Queue::size();
            $checks['queue'] = [
                'status' => 'ok',
                'message' => 'Queue is accessible',
                'pending_jobs' => $queueSize
            ];
        } catch (\Exception $e) {
            $checks['queue'] = [
                'status' => 'error',
                'message' => 'Queue check failed: ' . $e->getMessage()
            ];
            $overallStatus = 'error';
        }

        // Failed jobs check
        try {
            $failedJobsCount = DB::table('failed_jobs')->count();
            $checks['failed_jobs'] = [
                'status' => $failedJobsCount > 10 ? 'warning' : 'ok',
                'message' => "Failed jobs: {$failedJobsCount}",
                'count' => $failedJobsCount
            ];
            
            if ($failedJobsCount > 10) {
                $overallStatus = 'warning';
            }
        } catch (\Exception $e) {
            $checks['failed_jobs'] = [
                'status' => 'error',
                'message' => 'Failed to check failed jobs: ' . $e->getMessage()
            ];
        }

        // Running jobs check
        try {
            $runningJobs = IngestJob::whereIn('status', ['running', 'queued', 'processing_large_files'])
                ->where('created_at', '>', now()->subHours(24))
                ->count();
            
            $stuckJobs = IngestJob::where('status', 'running')
                ->where('updated_at', '<', now()->subHours(2))
                ->count();

            $checks['jobs'] = [
                'status' => $stuckJobs > 0 ? 'warning' : 'ok',
                'message' => "Active jobs: {$runningJobs}, Stuck jobs: {$stuckJobs}",
                'running_jobs' => $runningJobs,
                'stuck_jobs' => $stuckJobs
            ];

            if ($stuckJobs > 0) {
                $overallStatus = 'warning';
            }
        } catch (\Exception $e) {
            $checks['jobs'] = [
                'status' => 'error',
                'message' => 'Failed to check jobs: ' . $e->getMessage()
            ];
        }

        // Storage check
        try {
            $storagePath = storage_path('app');
            $writable = is_writable($storagePath);
            $checks['storage'] = [
                'status' => $writable ? 'ok' : 'error',
                'message' => $writable ? 'Storage is writable' : 'Storage is not writable',
                'path' => $storagePath
            ];

            if (!$writable) {
                $overallStatus = 'error';
            }
        } catch (\Exception $e) {
            $checks['storage'] = [
                'status' => 'error',
                'message' => 'Storage check failed: ' . $e->getMessage()
            ];
            $overallStatus = 'error';
        }

        $statusCode = match($overallStatus) {
            'ok' => 200,
            'warning' => 200, // Still operational but needs attention
            'error' => 503,
            default => 500
        };

        return response()->json([
            'status' => $overallStatus,
            'timestamp' => now()->toISOString(),
            'service' => 'KHub API',
            'version' => config('app.version', '1.0.0'),
            'checks' => $checks
        ], $statusCode);
    }

    /**
     * Metrics endpoint for monitoring
     */
    public function metrics()
    {
        try {
            $metrics = [
                'timestamp' => now()->toISOString(),
                'database' => [
                    'connections' => DB::getConnections(),
                ],
                'queue' => [
                    'size' => Queue::size(),
                    'failed_jobs' => DB::table('failed_jobs')->count(),
                ],
                'jobs' => [
                    'running' => IngestJob::where('status', 'running')->count(),
                    'queued' => IngestJob::where('status', 'queued')->count(),
                    'completed_today' => IngestJob::where('status', 'completed')
                        ->whereDate('updated_at', today())
                        ->count(),
                    'failed_today' => IngestJob::where('status', 'failed')
                        ->whereDate('updated_at', today())
                        ->count(),
                ],
                'documents' => [
                    'total' => DB::table('documents')->count(),
                    'chunks' => DB::table('chunks')->count(),
                    'conversations' => DB::table('conversations')->count(),
                    'messages' => DB::table('messages')->count(),
                ],
                'system' => [
                    'memory_usage' => memory_get_usage(true),
                    'memory_peak' => memory_get_peak_usage(true),
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                ]
            ];

            return response()->json($metrics);
        } catch (\Exception $e) {
            Log::error('Metrics collection failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to collect metrics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Alert on job failures
     */
    public function alertJobFailures()
    {
        try {
            $recentFailures = IngestJob::where('status', 'failed')
                ->where('updated_at', '>', now()->subHours(1))
                ->with('connector')
                ->get();

            if ($recentFailures->isEmpty()) {
                return response()->json([
                    'message' => 'No recent job failures',
                    'count' => 0
                ]);
            }

            $alerts = [];
            foreach ($recentFailures as $job) {
                $alerts[] = [
                    'job_id' => $job->id,
                    'connector_id' => $job->connector_id,
                    'connector_type' => $job->connector?->type,
                    'org_id' => $job->org_id,
                    'failed_at' => $job->updated_at,
                    'error_log' => $job->error_log,
                ];
            }

            // Log the alert
            Log::warning('Job failure alert', [
                'failure_count' => $recentFailures->count(),
                'failures' => $alerts
            ]);

            return response()->json([
                'alert' => true,
                'message' => "{$recentFailures->count()} job(s) failed in the last hour",
                'count' => $recentFailures->count(),
                'failures' => $alerts
            ]);

        } catch (\Exception $e) {
            Log::error('Job failure alert check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to check job failures',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
