<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Log;

class SocialAuthController extends Controller
{
    /**
     * Redirect to Google OAuth (for login/signup, NOT Google Drive connector)
     */
    public function redirectToGoogle()
    {
        // Use google driver but override redirect to avoid conflict with Google Drive connector
        return Socialite::driver('google')
            ->redirectUrl(env('APP_URL', 'http://localhost:8000') . '/api/auth/google/callback')
            ->stateless()
            ->redirect();
    }

    /**
     * Handle Google OAuth callback (for login/signup, NOT Google Drive connector)
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            // Get user info from Google
            // Use google driver but override redirect to avoid conflict with Google Drive connector
            $googleUser = Socialite::driver('google')
                ->redirectUrl(env('APP_URL', 'http://localhost:8000') . '/api/auth/google/callback')
                ->stateless()
                ->user();
            
            Log::info('Google OAuth callback received', [
                'email' => $googleUser->email,
                'name' => $googleUser->name,
                'google_id' => $googleUser->id,
            ]);

            // Find or create user
            $user = User::where('email', $googleUser->email)->first();

            if ($user) {
                // Update Google ID if not set
                if (!$user->google_id) {
                    $user->google_id = $googleUser->id;
                    $user->save();
                }

                Log::info('Existing user logged in via Google', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            } else {
                // Create new user
                $user = $this->createUserFromGoogle($googleUser);
                
                // Create getting started guide for new user
                $organization = Organization::find($user->org_id);
                if ($organization) {
                    \App\Services\OnboardingService::createGettingStartedGuide($organization);
                }
                
                Log::info('New user created via Google', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }

            // Create token for API authentication
            $token = $user->createToken('google-auth-token')->plainTextToken;

            // Redirect to frontend with token
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $redirectUrl = "{$frontendUrl}/auth/google/callback?token={$token}";

            return redirect($redirectUrl);

        } catch (\Exception $e) {
            Log::error('Google OAuth failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Redirect to frontend with error
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect("{$frontendUrl}/login?error=google_auth_failed");
        }
    }

    /**
     * Create a new user from Google data
     */
    private function createUserFromGoogle($googleUser): User
    {
        // Create organization for the user
        $organization = Organization::create([
            'id' => (string) Str::uuid(),
            'name' => $googleUser->name . "'s Organization",
        ]);

        // Create user
        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => $googleUser->name,
            'email' => $googleUser->email,
            'password' => Hash::make(Str::random(32)), // Random password (won't be used)
            'google_id' => $googleUser->id,
            'org_id' => $organization->id,
            'role' => 'admin', // First user in org is admin
            'email_verified_at' => now(), // Auto-verify email from Google
        ]);

        return $user;
    }

    /**
     * Handle token from frontend after Google OAuth
     * This endpoint receives the token from the frontend callback
     */
    public function verifyGoogleToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        try {
            // The token is already validated by Sanctum middleware
            // Just return the user data
            $user = $request->user();

            return response()->json([
                'user' => $user,
                'token' => $request->token,
            ]);
        } catch (\Exception $e) {
            Log::error('Token verification failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Invalid token',
            ], 401);
        }
    }
}

