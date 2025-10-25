<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'organization' => $user->organization,
            'token' => $token,
        ]);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $validated['email'])->first();

        Log::info('User lookup result', [
            'email' => $validated['email'],
            'password_length' => strlen($validated['password']),
            'password_first_chars' => substr($validated['password'], 0, 3),
            'user_found' => $user ? true : false,
            'user_id' => $user?->id,
        ]);

        if (!$user) {
            Log::warning('Login failed - user not found', [
                'email' => $validated['email'],
            ]);
            
            return response()->json([
                'message' => 'Invalid credentials.',
                'errors' => [
                    'email' => ['Invalid credentials.']
                ]
            ], 401);
        }

        if (!Hash::check($validated['password'], $user->password)) {
            Log::warning('Login failed - password mismatch', [
                'email' => $validated['email'],
                'user_id' => $user->id,
                'password_provided_length' => strlen($validated['password']),
                'password_hash_prefix' => substr($user->password, 0, 10),
            ]);
            
            return response()->json([
                'message' => 'Invalid credentials.',
                'errors' => [
                    'email' => ['Invalid credentials.']
                ]
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        Log::info('Login successful', [
            'user_id' => $user->id,
            'email' => $user->email,
            'token_length' => strlen($token),
        ]);

        return response()->json([
            'user' => $user,
            'organization' => $user->organization,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Validate token and return user information
     * This endpoint provides better error handling for authentication issues
     * Note: This route is not protected by auth:sanctum middleware to allow token validation
     */
    public function validateToken(Request $request)
    {
        try {
            // Check if Authorization header is present
            $authHeader = $request->header('Authorization');
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                Log::warning('Token validation failed - no authorization header', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'message' => 'No authorization token provided'
                ], 401);
            }

            // Extract token from header
            $token = substr($authHeader, 7); // Remove 'Bearer ' prefix
            
            // Find the token in the database
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            
            if (!$accessToken) {
                Log::warning('Token validation failed - token not found', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'message' => 'Invalid or expired token'
                ], 401);
            }

            // Check if token is expired
            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                Log::warning('Token validation failed - token expired', [
                    'token_id' => $accessToken->id,
                    'expires_at' => $accessToken->expires_at,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'message' => 'Token has expired'
                ], 401);
            }

            // Get the user
            $user = $accessToken->tokenable;
            
            if (!$user) {
                Log::warning('Token validation failed - user not found', [
                    'token_id' => $accessToken->id,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'message' => 'User not found'
                ], 401);
            }

            Log::info('Token validation successful', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'valid' => true,
                'user' => $user,
                'organization' => $user->organization
            ]);

        } catch (\Exception $e) {
            Log::error('Token validation error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);
            
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Authentication error'
            ], 500);
        }
    }

    /**
     * Refresh user session and token
     * This helps with session management issues
     */
    public function refreshToken(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Delete old token
            $request->user()->currentAccessToken()?->delete();
            
            // Create new token
            $token = $user->createToken('api-token')->plainTextToken;

            Log::info('Token refreshed successfully', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return response()->json([
                'success' => true,
                'user' => $user,
                'organization' => $user->organization,
                'token' => $token
            ]);

        } catch (\Exception $e) {
            Log::error('Token refresh error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh token'
            ], 500);
        }
    }
}