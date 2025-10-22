<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RateLimitByUser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $key, int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $rateLimitKey = "rate_limit:{$key}:user:{$user->id}";
        $attempts = Cache::get($rateLimitKey, 0);

        if ($attempts >= $maxAttempts) {
            Log::warning('Rate limit exceeded', [
                'user_id' => $user->id,
                'key' => $key,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'status_code' => 429,
                'retry_after' => $decayMinutes * 60,
                'details' => [
                    'key' => $key,
                    'max_attempts' => $maxAttempts,
                    'decay_minutes' => $decayMinutes
                ]
            ], 429);
        }

        // Increment attempts
        Cache::put($rateLimitKey, $attempts + 1, now()->addMinutes($decayMinutes));

        $response = $next($request);

        // Add rate limit headers
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxAttempts - $attempts - 1));
        $response->headers->set('X-RateLimit-Reset', now()->addMinutes($decayMinutes)->timestamp);

        return $response;
    }
}
