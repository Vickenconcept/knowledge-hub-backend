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

        // Get connector ID from request (passed from frontend)
        $connectorId = $request->get('connector_id');
        
        if (!$connectorId) {
            return response()->json([
                'error' => 'Connector ID is required for Dropbox OAuth'
            ], 400);
        }

        // Verify connector exists and belongs to user's org
        $connector = Connector::where('id', $connectorId)
            ->where('org_id', $orgId)
            ->where('type', 'dropbox')
            ->first();

        if (!$connector) {
            return response()->json([
                'error' => 'Connector not found or access denied'
            ], 404);
        }

        // Generate state token for security
        $state = base64_encode(json_encode([
            'org_id' => $orgId,
            'user_id' => $user->id,
            'connector_id' => $connectorId,
            'timestamp' => time(),
        ]));

        $authUrl = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'token_access_type' => 'offline', // Request refresh token
        ]);

        Log::info('🚀 DROPBOX OAUTH STARTED', [
            'connector_id' => $connector->id,
            'connection_scope' => $connector->connection_scope,
            'workspace_name' => $connector->workspace_name,
            'user_id' => $user->id,
            'org_id' => $orgId,
            'access_type' => $connector->connection_scope === 'personal' ? '👤 PERSONAL' : '🏢 ORGANIZATION'
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
            $connectorId = $stateData['connector_id'];

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

            // Get account info to extract display name
            $accountInfo = null;
            $accountName = 'Dropbox';
            
            try {
                $accountResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $tokenData['access_token'],
                    'Content-Type' => 'application/json',
                ])
                ->timeout(10)
                ->withBody('null', 'application/json')
                ->post('https://api.dropboxapi.com/2/users/get_current_account');
                
                if ($accountResponse->successful()) {
                    $accountInfo = $accountResponse->json();
                    $accountName = $accountInfo['name']['display_name'] ?? 
                                   $accountInfo['name']['familiar_name'] ?? 
                                   $accountInfo['email'] ?? 
                                   'Dropbox';
                    
            Log::info('✅ DROPBOX ACCOUNT INFO RETRIEVED', [
                'account_name' => $accountName,
                'email' => $accountInfo['email'] ?? null,
                'account_id' => $accountInfo['account_id'] ?? null,
            ]);
                } else {
                    Log::warning('Dropbox account info request failed', [
                        'status' => $accountResponse->status(),
                        'body' => $accountResponse->body(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get Dropbox account info, using default label', [
                    'error' => $e->getMessage()
                ]);
            }

            // Find the specific connector by ID
            $connector = Connector::where('id', $connectorId)
                ->where('org_id', $orgId)
                ->where('type', 'dropbox')
                ->first();

            if (!$connector) {
                Log::error('Dropbox connector not found', [
                    'connector_id' => $connectorId,
                    'org_id' => $orgId
                ]);
                return redirect(config('app.frontend_url') . '/connectors?error=connector_not_found');
            }

            // Update the specific connector with tokens and account info
            $connector->update([
                'encrypted_tokens' => encrypt(json_encode($tokenData)),
                'status' => 'connected',
                'label' => $accountName,
                'metadata' => json_encode([
                    'connected_at' => now()->toIso8601String(),
                    'account_id' => $accountInfo['account_id'] ?? null,
                    'email' => $accountInfo['email'] ?? null,
                    'display_name' => $accountName,
                ]),
            ]);

            Log::info('🎉 DROPBOX CONNECTOR CONNECTED SUCCESSFULLY', [
                'connector_id' => $connector->id,
                'org_id' => $orgId,
                'connection_scope' => $connector->connection_scope,
                'workspace_name' => $connector->workspace_name,
                'account_name' => $accountName,
                'account_email' => $accountInfo['email'] ?? null,
                'access_type' => $connector->connection_scope === 'personal' ? '👤 PERSONAL WORKSPACE' : '🏢 ORGANIZATION WORKSPACE',
                'workspace_access' => $connector->connection_scope === 'personal' 
                    ? 'Personal files and folders only' 
                    : 'Personal files + shared folders + team folders + external collaborations'
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

