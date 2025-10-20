<?php

namespace App\Http\Controllers\Connectors;

use App\Models\Connector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Notion Connector Controller
 * 
 * Handles OAuth authentication and integration with Notion
 */
class NotionController extends BaseConnectorController
{
    protected function getConnectorType(): string
    {
        return 'notion';
    }
    
    protected function getConnectorLabel(): string
    {
        return 'Notion';
    }

    /**
     * Get Notion OAuth authorization URL
     */
    public function authUrl(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        $clientId = env('NOTION_CLIENT_ID');
        $redirectUri = env('NOTION_REDIRECT_URI');

        if (!$clientId || !$redirectUri) {
            return response()->json([
                'error' => 'Notion integration not configured. Please set NOTION_CLIENT_ID and NOTION_REDIRECT_URI in .env'
            ], 500);
        }

        // Generate state token for security
        $state = base64_encode(json_encode([
            'org_id' => $orgId,
            'user_id' => $user->id,
            'timestamp' => time(),
        ]));

        $authUrl = 'https://api.notion.com/v1/oauth/authorize?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'owner' => 'user',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        Log::info('Notion OAuth URL generated', [
            'org_id' => $orgId,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'auth_url' => $authUrl,
            'state' => $state,
        ]);
    }

    /**
     * Handle OAuth callback from Notion
     */
    public function handleCallback(Request $request)
    {
        $code = $request->get('code');
        $state = $request->get('state');
        $error = $request->get('error');

        // Handle OAuth errors
        if ($error) {
            Log::error('Notion OAuth error', ['error' => $error]);
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
            $credentials = base64_encode(env('NOTION_CLIENT_ID') . ':' . env('NOTION_CLIENT_SECRET'));
            
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->post('https://api.notion.com/v1/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => env('NOTION_REDIRECT_URI'),
            ]);

            if (!$response->successful()) {
                Log::error('Notion token exchange failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return redirect(config('app.frontend_url') . '/connectors?error=token_exchange_failed');
            }

            $tokenData = $response->json();

            Log::info('Notion token exchange successful', [
                'has_access_token' => isset($tokenData['access_token']),
                'workspace_name' => $tokenData['workspace_name'] ?? null,
            ]);

            // Extract workspace name for label
            $workspaceName = $tokenData['workspace_name'] ?? 'Notion';

            // Create or update connector
            $connector = $this->createOrUpdateConnector(
                $orgId,
                $tokenData,
                [
                    'workspace_name' => $tokenData['workspace_name'] ?? '',
                    'workspace_id' => $tokenData['workspace_id'] ?? '',
                    'workspace_icon' => $tokenData['workspace_icon'] ?? '',
                    'bot_id' => $tokenData['bot_id'] ?? '',
                    'owner_type' => $tokenData['owner']['type'] ?? '',
                    'owner_user' => $tokenData['owner']['user'] ?? null,
                    'connected_at' => now()->toIso8601String(),
                ],
                $workspaceName // Pass workspace name as label
            );

            return redirect(config('app.frontend_url') . '/connectors?success=notion_connected');

        } catch (\Exception $e) {
            Log::error('Notion callback error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect(config('app.frontend_url') . '/connectors?error=connection_failed');
        }
    }
}

