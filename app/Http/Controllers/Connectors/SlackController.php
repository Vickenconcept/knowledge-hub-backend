<?php

namespace App\Http\Controllers\Connectors;

use App\Models\Connector;
use App\Services\SlackService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * Slack Connector Controller
 * 
 * Handles OAuth authentication and integration with Slack workspaces
 */
class SlackController extends BaseConnectorController
{
    protected function getConnectorType(): string
    {
        return 'slack';
    }
    
    protected function getConnectorLabel(): string
    {
        return 'Slack';
    }
    
    /**
     * Get Slack OAuth authorization URL
     */
    public function getAuthUrl(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        // Get connector ID from request (passed from frontend)
        $connectorId = $request->get('connector_id');
        
        if (!$connectorId) {
            return response()->json([
                'error' => 'Connector ID is required for Slack OAuth'
            ], 400);
        }

        // Verify connector exists and belongs to user's org
        $connector = Connector::where('id', $connectorId)
            ->where('org_id', $orgId)
            ->where('type', 'slack')
            ->first();

        if (!$connector) {
            return response()->json([
                'error' => 'Connector not found or access denied'
            ], 404);
        }

        $slack = new SlackService();

        // State includes connector id and scope for proper routing
        $state = base64_encode(json_encode([
            'connector_id' => $connector->id,
            'connection_scope' => $connector->connection_scope,
            'workspace_name' => $connector->workspace_name,
        ]));

        return response()->json([
            'connector_id' => $connector->id,
            'url' => $slack->getAuthUrl($state),
        ]);
    }

    /**
     * Handle Slack OAuth callback
     */
    public function handleCallback(Request $request)
    {
        Log::info('Slack OAuth callback called', [
            'method' => $request->method(),
            'query_params' => $request->query(),
        ]);

        $code = (string) $request->query('code', '');
        $stateB64 = (string) $request->query('state', '');
        
        if ($code === '' || $stateB64 === '') {
            return $this->redirectToFrontend(false, 'missing_code_or_state');
        }

        $state = json_decode(base64_decode($stateB64), true) ?: [];
        $connectorId = $state['connector_id'] ?? null;
        $connectionScope = $state['connection_scope'] ?? null;
        $workspaceName = $state['workspace_name'] ?? null;
        
        if (!$connectorId) {
            return $this->redirectToFrontend(false, 'invalid_state');
        }

        // Find the connector
        $connector = Connector::find($connectorId);
        if (!$connector) {
            return $this->redirectToFrontend(false, 'connector_not_found');
        }

        Log::info('Slack OAuth callback processing', [
            'connector_id' => $connectorId,
            'connection_scope' => $connectionScope,
            'workspace_name' => $workspaceName,
            'connector_scope' => $connector->connection_scope
        ]);

        try {
            $slack = new SlackService();
            
            // Exchange code for access token
            $tokenData = $slack->exchangeCode($code);
            
            Log::info('Slack OAuth token exchange successful', [
                'connector_id' => $connector->id,
                'team_id' => $tokenData['team_id'],
                'team_name' => $tokenData['team_name'],
            ]);

            // Get team info
            $teamInfo = $slack->getTeamInfo($tokenData['access_token']);

            // Store tokens in encrypted_tokens column
            $connector->encrypted_tokens = encrypt(json_encode([
                'access_token' => $tokenData['access_token'], // Bot token (xoxb-)
                'user_token' => $tokenData['user_token'] ?? null, // User token (xoxp-) - for files!
                'team_id' => $tokenData['team_id'],
                'team_name' => $tokenData['team_name'],
            ]));
            
            $connector->metadata = [
                'team_id' => $tokenData['team_id'],
                'team_name' => $tokenData['team_name'],
                'team_domain' => $teamInfo['domain'] ?? null,
                'team_icon' => $teamInfo['icon']['image_68'] ?? null,
                'user_id' => $tokenData['user_id'],
                'bot_user_id' => $tokenData['bot_user_id'],
            ];
            $connector->label = $teamInfo['name'] ?? 'Slack';
            $connector->status = 'connected';
            $connector->save();

            Log::info('Slack connector saved successfully', [
                'connector_id' => $connector->id,
                'status' => $connector->status,
            ]);

            return $this->redirectToFrontend(true);
            
        } catch (\Exception $e) {
            Log::error('Slack OAuth failed', [
                'connector_id' => $connector->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->redirectToFrontend(false, 'oauth_exchange_failed');
        }
    }
    
    /**
     * Disconnect Slack and leave all joined channels
     */
    public function disconnect(Request $request, string $id)
    {
        $connector = Connector::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->where('type', 'slack')
            ->firstOrFail();

        Log::info('Disconnecting Slack connector', [
            'connector_id' => $connector->id,
            'org_id' => $connector->org_id,
        ]);

        // Leave all joined channels before disconnecting
        try {
            $tokens = $connector->encrypted_tokens ? json_decode(decrypt($connector->encrypted_tokens), true) : null;
            $accessToken = $tokens['access_token'] ?? null;
            
            if ($accessToken) {
                $joinedChannels = $connector->metadata['joined_channels'] ?? [];
                
                if (!empty($joinedChannels)) {
                    Log::info('Leaving Slack channels before disconnect', [
                        'connector_id' => $connector->id,
                        'channels_to_leave' => count($joinedChannels),
                    ]);
                    
                    $slack = new SlackService();
                    $leaveResults = $slack->leaveChannels($accessToken, $joinedChannels);
                    
                    Log::info('Left Slack channels', [
                        'connector_id' => $connector->id,
                        'total' => $leaveResults['total'],
                        'succeeded' => $leaveResults['succeeded'],
                        'failed' => $leaveResults['failed'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Could not leave Slack channels', [
                'connector_id' => $connector->id,
                'error' => $e->getMessage(),
            ]);
            // Continue with deletion even if leaving channels fails
        }

        // Mark as disconnected (don't delete yet - handled by parent controller)
        $connector->status = 'disconnected';
        $connector->save();

        return response()->json([
            'message' => 'Slack disconnected successfully',
        ]);
    }
}

