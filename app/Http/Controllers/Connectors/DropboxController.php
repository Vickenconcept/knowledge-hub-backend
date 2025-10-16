<?php

namespace App\Http\Controllers\Connectors;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dropbox Connector Controller
 * 
 * Handles OAuth authentication and integration with Dropbox
 */
class DropboxController extends BaseConnectorController
{
    protected function getConnectorType(): string
    {
        return 'dropbox';
    }
    
    protected function getConnectorLabel(): string
    {
        return 'Dropbox';
    }
    

    /**
     * Get Dropbox OAuth authorization URL
     */
    public function authUrl(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        $clientId = config('services.dropbox.client_id');
        $redirectUri = config('services.dropbox.redirect');

        if (!$clientId || !$redirectUri) {
            return response()->json([
                'error' => 'Dropbox integration not configured. Please set DROPBOX_CLIENT_ID and DROPBOX_REDIRECT_URI in .env'
            ], 500);
        }

        // Generate state token for security
        $state = base64_encode(json_encode([
            'org_id' => $orgId,
            'user_id' => $user->id,
            'timestamp' => time(),
        ]));

        $authUrl = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'token_access_type' => 'offline', // Request refresh token
        ]);

        return response()->json([
            'auth_url' => $authUrl,
            'state' => $state,
        ]);
    }

    /**
     * Handle OAuth callback from Dropbox
     */
    public function handleCallback(Request $request)
    {
        $code = $request->get('code');
        $state = $request->get('state');
        $error = $request->get('error');

        // Handle OAuth errors
        if ($error) {
            Log::error('Dropbox OAuth error', ['error' => $error]);
            return redirect(config('app.frontend_url') . '/connectors?error=' . urlencode($error));
        }

        if (!$code) {
            return redirect(config('app.frontend_url') . '/connectors?error=no_code');
        }

        try {
            // Decode state
            $stateData = json_decode(base64_decode($state), true);
            $orgId = $stateData['org_id'];
            $userId = $stateData['user_id'];

            // Exchange code for tokens
            $response = Http::asForm()
                ->timeout(30)
                ->post('https://api.dropboxapi.com/oauth2/token', [
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'client_id' => config('services.dropbox.client_id'),
                    'client_secret' => config('services.dropbox.client_secret'),
                    'redirect_uri' => config('services.dropbox.redirect'),
                ]);

            if (!$response->successful()) {
                Log::error('Dropbox token exchange failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return redirect(config('app.frontend_url') . '/connectors?error=token_exchange_failed');
            }

            $tokenData = $response->json();

            // Check if connector already exists
            $connector = Connector::where('org_id', $orgId)
                ->where('type', 'dropbox')
                ->first();

            if ($connector) {
                // Update existing connector
                $connector->update([
                    'encrypted_tokens' => encrypt(json_encode($tokenData)),
                    'status' => 'connected',
                    'label' => 'Dropbox',
                    'metadata' => json_encode([
                        'connected_at' => now()->toIso8601String(),
                    ]),
                ]);
            } else {
                // Create new connector
                $connector = Connector::create([
                    'id' => (string) Str::uuid(),
                    'org_id' => $orgId,
                    'type' => 'dropbox',
                    'label' => 'Dropbox',
                    'status' => 'connected',
                    'encrypted_tokens' => encrypt(json_encode($tokenData)),
                    'metadata' => json_encode([
                        'connected_at' => now()->toIso8601String(),
                    ]),
                ]);
            }

            Log::info('Dropbox connector created/updated', [
                'connector_id' => $connector->id,
                'org_id' => $orgId,
            ]);

            return redirect(config('app.frontend_url') . '/connectors?dropbox_connected=true');

        } catch (\Exception $e) {
            Log::error('Dropbox callback error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect(config('app.frontend_url') . '/connectors?error=connection_failed');
        }
    }

    /**
     * Disconnect Dropbox connector
     */
    public function disconnect(Request $request, $connectorId)
    {
        $user = $request->user();
        
        $connector = Connector::where('id', $connectorId)
            ->where('org_id', $user->org_id)
            ->where('type', 'dropbox')
            ->firstOrFail();

        // Optionally revoke token with Dropbox
        try {
            $tokens = json_decode(decrypt($connector->encrypted_tokens), true);
            if (isset($tokens['access_token'])) {
                Http::withToken($tokens['access_token'])
                    ->timeout(10)
                    ->post('https://api.dropboxapi.com/2/auth/token/revoke');
            }
        } catch (\Exception $e) {
            Log::warning('Failed to revoke Dropbox token', ['error' => $e->getMessage()]);
        }

        $connector->update(['status' => 'disconnected']);

        return response()->json(['message' => 'Dropbox disconnected successfully']);
    }
}

