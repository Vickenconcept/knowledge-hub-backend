<?php

namespace App\Http\Controllers\Connectors;

use App\Models\Connector;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Google\Client as GoogleClient;

/**
 * Google Drive Connector Controller
 * 
 * Handles OAuth authentication and integration with Google Drive
 */
class GoogleDriveController extends BaseConnectorController
{
    protected function getConnectorType(): string
    {
        return 'google_drive';
    }
    
    protected function getConnectorLabel(): string
    {
        return 'Google Drive';
    }
    
    /**
     * Get Google Drive OAuth authorization URL
     */
    public function getAuthUrl(Request $request)
    {
        $client = new GoogleClient();
        $client->setClientId(env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
        $client->setAccessType('offline');
        $client->setPrompt('consent'); // Force consent screen to ensure all scopes are granted
        $client->addScope([
            'https://www.googleapis.com/auth/drive.readonly',
            'https://www.googleapis.com/auth/drive.metadata.readonly',
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ]);

        // Check if Google Drive connector already exists for this organization
        $existingConnector = Connector::where('org_id', $request->user()->org_id)
            ->where('type', 'google_drive')
            ->first();

        if ($existingConnector) {
            // Update existing connector if it's disconnected
            if ($existingConnector->status === 'disconnected') {
                $connector = $existingConnector;
            } else {
                return response()->json([
                    'message' => 'Google Drive is already connected for this organization'
                ], 409);
            }
        } else {
            // Create a new connector record
            $connector = Connector::create([
                'id' => (string) Str::uuid(),
                'org_id' => $request->user()->org_id,
                'type' => 'google_drive',
                'label' => 'Google Drive',
                'status' => 'disconnected',
            ]);
        }

        // State can include connector id to reconcile on callback
        $state = json_encode(['connector_id' => $connector->id]);
        $client->setState(base64_encode($state));

        return response()->json([
            'connector_id' => $connector->id,
            'url' => $client->createAuthUrl(),
        ]);
    }

    /**
     * Handle Google Drive OAuth callback
     */
    public function handleCallback(Request $request)
    {
        Log::info('Google Drive OAuth callback called', [
            'method' => $request->method(),
            'query_params' => $request->query(),
            'headers' => $request->headers->all()
        ]);

        $code = (string) $request->query('code', '');
        $stateB64 = (string) $request->query('state', '');
        
        Log::info('OAuth callback params', [
            'code_length' => strlen($code),
            'state_b64_length' => strlen($stateB64),
            'has_code' => !empty($code),
            'has_state' => !empty($stateB64)
        ]);
        
        if ($code === '' || $stateB64 === '') {
            if ($request->isJson()) {
                return response()->json(['message' => 'Missing code or state'], 422);
            }
            return $this->redirectToFrontend(false, 'missing_code_or_state');
        }

        $state = json_decode(base64_decode($stateB64), true) ?: [];
        $connectorId = $state['connector_id'] ?? null;
        
        if (!$connectorId) {
            if ($request->isJson()) {
                return response()->json(['message' => 'Invalid state'], 422);
            }
            return $this->redirectToFrontend(false, 'invalid_state');
        }

        // Find the connector
        $connector = Connector::find($connectorId);
        if (!$connector) {
            if ($request->isJson()) {
                return response()->json(['message' => 'Connector not found'], 404);
            }
            return $this->redirectToFrontend(false, 'connector_not_found');
        }

        try {
            $client = new GoogleClient();
            $client->setClientId(env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
            $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));

            $token = $client->fetchAccessTokenWithAuthCode($code);
            
            Log::info('Token exchange result', [
                'has_error' => isset($token['error']),
                'error' => $token['error'] ?? null,
                'has_access_token' => isset($token['access_token']),
                'has_refresh_token' => isset($token['refresh_token'])
            ]);
            
            if (isset($token['error'])) {
                Log::error('OAuth token exchange failed', ['error' => $token['error']]);
                if ($request->isJson()) {
                    return response()->json(['message' => 'OAuth error', 'error' => $token['error']], 400);
                }
                return $this->redirectToFrontend(false, 'oauth_error');
            }

            $connector->encrypted_tokens = encrypt(json_encode($token));
            $connector->status = 'connected';
            $connector->save();

            Log::info('Google Drive connector updated successfully', [
                'connector_id' => $connector->id,
                'status' => $connector->status,
                'has_tokens' => !empty($connector->encrypted_tokens)
            ]);

            if ($request->isJson()) {
                return response()->json([
                    'message' => 'Google Drive connected',
                    'connector' => $connector,
                ]);
            }

            return $this->redirectToFrontend(true);
            
        } catch (\Exception $e) {
            Log::error('Google Drive OAuth failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->redirectToFrontend(false, 'connection_failed');
        }
    }
}

