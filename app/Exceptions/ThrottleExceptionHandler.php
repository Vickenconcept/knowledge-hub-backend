<?php

namespace App\Exceptions;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ThrottleExceptionHandler
{
    /**
     * Handle throttle exceptions and return proper JSON responses
     */
    public static function handle(ThrottleRequestsException $exception, Request $request): JsonResponse
    {
        // Check if this is an API request
        if ($request->expectsJson() || $request->is('api/*')) {
            $retryAfter = $exception->getHeaders()['Retry-After'] ?? 60;
            $minutes = ceil($retryAfter / 60);
            
            return response()->json([
                'success' => false,
                'message' => "Too many requests. Please wait {$minutes} minute(s) before trying again.",
                'error' => 'RATE_LIMIT_EXCEEDED',
                'retry_after' => $retryAfter,
                'retry_after_minutes' => $minutes
            ], 429);
        }
        
        // For non-API requests, return the default response
        return response()->view('errors.429', [], 429);
    }
}
