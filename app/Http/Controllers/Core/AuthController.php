<?php

namespace App\Http\Controllers\Core;

use App\Models\User;
use App\Models\Organization;
use App\Services\Core\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            'registered_from' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create user first so we can set them as owner
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => 'admin',
            'registered_from' => $this->resolveRegistrationSource($request, 'core_web'),
            'password' => Hash::make($request->password),
        ]);

        // Create organization with user as owner
        $organization = Organization::create([
            'id' => (string) Str::uuid(),
            'name' => "Organization",
            'owner_id' => $user->id,
        ]);

        // Now update user with org_id (we can't do it before organization exists)
        $user->org_id = $organization->id;
        $user->save();

        // Send welcome email for password-based signup
        EmailService::sendWelcomeEmailForPasswordSignup($user);

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

        if (!$user) {
            return response()->json([
                'message' => 'Invalid credentials.',
                'errors' => [
                    'email' => ['Invalid credentials.']
                ]
            ], 401);
        }

        if (!Hash::check($validated['password'], $user->password)) {
            
            return response()->json([
                'message' => 'Invalid credentials.',
                'errors' => [
                    'email' => ['Invalid credentials.']
                ]
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

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
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'message' => 'Invalid or expired token'
                ], 401);
            }

            // Check if token is expired
            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'message' => 'Token has expired'
                ], 401);
            }

            // Get the user
            $user = $accessToken->tokenable;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'message' => 'User not found'
                ], 401);
            }


            return response()->json([
                'success' => true,
                'valid' => true,
                'user' => $user,
                'organization' => $user->organization
            ]);

        } catch (\Exception $e) {
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

            return response()->json([
                'success' => true,
                'user' => $user,
                'organization' => $user->organization,
                'token' => $token
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh token'
            ], 500);
        }
    }

    private function resolveRegistrationSource(Request $request, string $fallback): string
    {
        $candidate = $request->input('registered_from')
            ?? $request->header('X-Client-App')
            ?? $fallback;

        $normalized = strtolower((string) $candidate);
        $normalized = preg_replace('/[^a-z0-9_\-.]/', '_', $normalized) ?? $fallback;
        $normalized = trim($normalized, '_-.');

        if ($normalized === '') {
            return $fallback;
        }

        return mb_substr($normalized, 0, 100);
    }
}