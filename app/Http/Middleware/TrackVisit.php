<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Visit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TrackVisit
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip health endpoints and static asset paths
        $path = $request->path();
        if (Str::startsWith($path, ['metrics', 'ready', 'health']) || Str::contains($path, ['storage/', 'vendor/'])) {
            return $next($request);
        }

        // Proceed
        $response = $next($request);

        try {
            $user = $request->user();
            Log::info('Visit logging start', [
                'ip' => $request->ip(),
                'method' => $request->method(),
                'path' => '/' . ltrim($path, '/'),
                'referrer' => (string) $request->header('Referer'),
                'user_id' => $user?->id,
                'org_id' => $user?->org_id,
            ]);
            Visit::create([
                'id' => (string) Str::uuid(),
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->header('User-Agent'),
                'method' => $request->method(),
                'path' => '/' . ltrim($path, '/'),
                'referrer' => (string) $request->header('Referer'),
                'user_id' => $user?->id,
                'org_id' => $user?->org_id,
            ]);
            Log::info('Visit logging saved');
        } catch (\Throwable $e) {
            // Never block the request if logging fails
            Log::warning('Visit logging failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }
}


