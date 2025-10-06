<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        
        // Allow localhost origins for development
        $allowedOrigins = [
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:5173', // Vite dev server
            'http://127.0.0.1:5173',
            'http://localhost:4173', // Vite preview
            'http://127.0.0.1:4173',
        ];
        
        // For development, allow any localhost origin
        if ($origin && (
            str_starts_with($origin, 'http://localhost:') || 
            str_starts_with($origin, 'http://127.0.0.1:')
        )) {
            $allowedOrigin = $origin;
        } else {
            $allowedOrigin = 'http://localhost:3000';
        }

        // Handle preflight requests
        if ($request->isMethod('OPTIONS')) {
            return response('', 200)
                ->header('Access-Control-Allow-Origin', $allowedOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'false')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        // Add CORS headers to all responses
        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN');
        $response->headers->set('Access-Control-Allow-Credentials', 'false');

        return $response;
    }
}
