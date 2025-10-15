<?php

namespace App\Http\Controllers;

use App\Models\Connector;
use App\Models\IngestJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\IngestConnectorJob;
use Google\Client as GoogleClient;

class ConnectorController extends Controller
{
    /**
     * Get usage status and limits for organization
     */
    public function getUsageStatus(Request $request)
    {
        $orgId = $request->user()->org_id;
        
        $status = \App\Services\UsageLimitService::getUsageStatus($orgId);
        
        return response()->json($status);
    }
    
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        
        // Clean up stuck jobs (running/queued for more than 1 hour)
        IngestJob::where('org_id', $orgId)
            ->whereIn('status', ['running', 'queued'])
            ->where('created_at', '<', now()->subHour())
            ->update(['status' => 'failed']);
        
        $connectors = Connector::where('org_id', $orgId)
            ->withCount(['documents', 'chunks'])
            ->get();
        
        // Rename count fields to match frontend expectations and check for running jobs
        foreach ($connectors as $connector) {
            $connector->documents_count = $connector->documents_count ?? 0;
            $connector->chunks_count = $connector->chunks_count ?? 0;
            
            // Check for running ingest jobs and update connector status
            $runningJob = IngestJob::where('connector_id', $connector->id)
                ->whereIn('status', ['running', 'queued', 'processing_large_files'])
                ->first();
            
            if ($runningJob) {
                $connector->status = 'syncing';
            }
        }
        
        return response()->json($connectors);
    }

    public function create(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|string',
            'label' => 'nullable|string|max:255',
        ]);

        $connector = Connector::create([
            'id' => (string) Str::uuid(),
            'org_id' => $request->user()->org_id,
            'type' => $validated['type'],
            'label' => $validated['label'] ?? ucfirst($validated['type']),
            'status' => 'disconnected',
        ]);

        $authUrl = url("/connectors/oauth/{$connector->id}");

        return response()->json([
            'connector' => $connector,
            'auth_url' => $authUrl,
        ]);
    }

    public function oauthCallback(Request $request, string $id)
    {
        $connector = Connector::findOrFail($id);

        $data = $request->only(['access_token','refresh_token','expires_at']);
        $connector->encrypted_tokens = encrypt(json_encode($data));
        $connector->status = 'connected';
        $connector->save();

        return response()->json([
            'message' => 'Connector connected successfully',
            'connector' => $connector,
        ]);
    }

    public function getJobStatus(Request $request, string $connectorId)
    {
        $connector = Connector::where('id', $connectorId)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();

        // Get the latest job for this connector
        $job = IngestJob::where('connector_id', $connectorId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$job) {
            return response()->json([
                'has_job' => false,
                'message' => 'No sync job found'
            ]);
        }

        // Ensure stats is an array (handle PostgreSQL JSON type)
        $stats = is_array($job->stats) ? $job->stats : json_decode($job->stats, true) ?? [];
        
        // Calculate progress percentage based on connector type
        $progressPercentage = 0;
        if (isset($stats['total_channels']) && $stats['total_channels'] > 0) {
            // Slack: use channels
            $progressPercentage = round(($stats['processed_channels'] / $stats['total_channels']) * 100, 1);
        } elseif (isset($stats['total_files']) && $stats['total_files'] > 0) {
            // Google Drive, Dropbox: use files
            $progressPercentage = round(($stats['processed_files'] / $stats['total_files']) * 100, 1);
        }
        
        \Log::debug('Job status API response', [
            'connector_id' => $connectorId,
            'progress_percentage' => $progressPercentage,
            'stats' => $stats,
        ]);
        
        return response()->json([
            'has_job' => true,
            'job_id' => $job->id,
            'status' => $job->status,
            'stats' => $stats,
            'created_at' => $job->created_at,
            'finished_at' => $job->finished_at,
            'progress_percentage' => $progressPercentage,
        ]);
    }

    public function startIngest(Request $request, string $id)
    {
        \Log::info('=== SYNC STARTED ===', [
            'connector_id' => $id,
            'user_id' => $request->user()->id,
            'org_id' => $request->user()->org_id
        ]);

        $orgId = $request->user()->org_id;
        
        // CHECK IF ALREADY AT DOCUMENT LIMIT
        $docLimit = \App\Services\UsageLimitService::canAddDocument($orgId);
        if (!$docLimit['allowed']) {
            return response()->json([
                'error' => 'Document limit exceeded',
                'message' => $docLimit['reason'],
                'current_usage' => $docLimit['current_usage'],
                'limit' => $docLimit['limit'],
                'tier' => $docLimit['tier'],
                'upgrade_required' => true,
            ], 429);
        }
        
        // Warn if close to limit
        if ($docLimit['remaining'] && $docLimit['remaining'] < 10) {
            \Log::warning('Organization close to document limit', [
                'org_id' => $orgId,
                'current' => $docLimit['current_usage'],
                'limit' => $docLimit['limit'],
                'remaining' => $docLimit['remaining'],
            ]);
        }

        $connector = Connector::where('id', $id)
            ->where('org_id', $orgId)
            ->firstOrFail();

        \Log::info('Connector found for sync', [
            'connector_id' => $connector->id,
            'type' => $connector->type,
            'status' => $connector->status,
            'has_tokens' => !empty($connector->encrypted_tokens)
        ]);

        // Dispatch job - the job itself will create the IngestJob record
        IngestConnectorJob::dispatch($connector->id, $request->user()->org_id)->onQueue('default');

        \Log::info('IngestConnectorJob dispatched to queue', [
            'connector_id' => $connector->id,
            'org_id' => $request->user()->org_id,
            'queue' => 'default'
        ]);

        return response()->json([
            'message' => 'Ingestion started',
            'job_id' => null, // Job will be created by the job itself
        ]);
    }

    public function stopSync(Request $request, string $id)
    {
        $connector = Connector::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();

        \Log::info('=== STOP SYNC REQUESTED ===', [
            'connector_id' => $connector->id,
            'user_id' => $request->user()->id,
            'current_status' => $connector->status
        ]);

        // Find any running/queued jobs for this connector
        $runningJobs = IngestJob::where('connector_id', $connector->id)
            ->whereIn('status', ['running', 'queued', 'processing_large_files'])
            ->get();

        if ($runningJobs->isEmpty()) {
            \Log::info('No running jobs found to stop', ['connector_id' => $connector->id]);
            return response()->json(['message' => 'No sync in progress'], 400);
        }

        // Mark all running jobs as cancelled
        foreach ($runningJobs as $job) {
            $oldStatus = $job->status;
            $job->status = 'cancelled';
            $job->finished_at = now();
            
            // Update stats to indicate cancellation
            $stats = $job->stats ?? [];
            $stats['cancelled_at'] = now()->toISOString();
            $stats['cancelled_by'] = $request->user()->id;
            $stats['current_file'] = 'Cancelled by user';
            $job->stats = $stats;
            
            $job->save();

            \Log::info('IngestJob marked as cancelled', [
                'job_id' => $job->id,
                'old_status' => $oldStatus,
                'new_status' => 'cancelled'
            ]);
        }

        // Reset connector status
        $connector->status = 'connected';
        $connector->save();

        \Log::info('Connector status reset after sync stop', [
            'connector_id' => $connector->id,
            'status' => 'connected'
        ]);

        return response()->json([
            'message' => 'Sync stopped successfully',
            'connector' => $connector,
            'cancelled_jobs' => $runningJobs->count()
        ]);
    }

    public function disconnect(Request $request, string $id)
    {
        $connector = Connector::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();

        \Log::info('Disconnecting and deleting connector', [
            'connector_id' => $connector->id,
            'type' => $connector->type,
            'user_id' => $request->user()->id,
            'documents_count' => $connector->documents()->count(),
        ]);

        // For Slack: Leave all joined channels before disconnecting
        if ($connector->type === 'slack') {
            try {
                $tokens = $connector->encrypted_tokens ? json_decode(decrypt($connector->encrypted_tokens), true) : null;
                $accessToken = $tokens['access_token'] ?? null;
                
                if ($accessToken) {
                    $joinedChannels = $connector->metadata['joined_channels'] ?? [];
                    
                    if (!empty($joinedChannels)) {
                        \Log::info('Leaving Slack channels before disconnect', [
                            'connector_id' => $connector->id,
                            'channels_to_leave' => count($joinedChannels),
                        ]);
                        
                        $slack = new \App\Services\SlackService();
                        $leaveResults = $slack->leaveChannels($accessToken, $joinedChannels);
                        
                        \Log::info('Left Slack channels', [
                            'connector_id' => $connector->id,
                            'total' => $leaveResults['total'],
                            'succeeded' => $leaveResults['succeeded'],
                            'failed' => $leaveResults['failed'],
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Could not leave Slack channels', [
                    'connector_id' => $connector->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue with deletion even if leaving channels fails
            }
        }

        // Delete associated data
        // Note: Documents, chunks, and ingest jobs will be cascade deleted
        // if foreign keys are set up, otherwise delete manually
        
        $documentsCount = $connector->documents()->count();
        $chunksCount = $connector->chunks()->count();
        
        // Get document IDs and chunk IDs for vector deletion
        $documentIds = $connector->documents()->pluck('id')->toArray();
        
        // Get all chunk IDs to delete vectors
        $chunkIds = \App\Models\Chunk::whereIn('document_id', $documentIds)
            ->pluck('id')
            ->toArray();
        
        // Delete vectors from database (set embedding to NULL)
        if (!empty($chunkIds)) {
            try {
                $vectorStore = new \App\Services\VectorStoreService();
                $vectorStore->delete($chunkIds);
                
                \Log::info('Deleted vectors from database', [
                    'chunk_count' => count($chunkIds),
                    'connector_id' => $connector->id,
                ]);
            } catch (\Exception $e) {
                \Log::warning('Could not delete vectors', [
                    'error' => $e->getMessage(),
                    'chunk_count' => count($chunkIds),
                ]);
                // Continue with deletion even if vector cleanup fails
            }
        }
        
        // Delete chunks from database
        \App\Models\Chunk::whereIn('document_id', $documentIds)->delete();
        
        // Delete documents
        $connector->documents()->delete();
        
        // Delete ingest jobs
        \App\Models\IngestJob::where('connector_id', $connector->id)->delete();
        
        // Delete the connector itself
        $connector->delete();

        \Log::info('Connector deleted successfully', [
            'connector_id' => $id,
            'type' => $connector->type,
            'documents_deleted' => $documentsCount,
            'chunks_deleted' => $chunksCount,
        ]);

        return response()->json([
            'message' => 'Connector disconnected and deleted successfully',
            'deleted' => [
                'documents' => $documentsCount,
                'chunks' => $chunksCount,
            ],
        ]);
    }

    public function getGoogleDriveAuthUrl(Request $request)
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
                return response()->json(['message' => 'Google Drive is already connected for this organization'], 409);
            }
        } else {
            // Create a new connector record
            $connector = Connector::create([
                'id' => (string) Str::uuid(),
                'org_id' => $request->user()->org_id,
                'type' => 'google_drive',
                'label' => 'GDrive',
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

    public function handleGoogleDriveCallback(Request $request)
    {
        \Log::info('Google Drive OAuth callback called', [
            'method' => $request->method(),
            'query_params' => $request->query(),
            'headers' => $request->headers->all()
        ]);

        $code = (string) $request->query('code', '');
        $stateB64 = (string) $request->query('state', '');
        
        \Log::info('OAuth callback params', [
            'code_length' => strlen($code),
            'state_b64_length' => strlen($stateB64),
            'has_code' => !empty($code),
            'has_state' => !empty($stateB64)
        ]);
        
        if ($code === '' || $stateB64 === '') {
            if ($request->isJson()) {
                return response()->json(['message' => 'Missing code or state'], 422);
            }
            // For GET requests (Google redirect), redirect to frontend with error
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=missing_code_or_state');
        }

        $state = json_decode(base64_decode($stateB64), true) ?: [];
        $connectorId = $state['connector_id'] ?? null;
        if (!$connectorId) {
            if ($request->isJson()) {
                return response()->json(['message' => 'Invalid state'], 422);
            }
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=invalid_state');
        }

        // Find the connector
        $connector = Connector::find($connectorId);
        if (!$connector) {
            if ($request->isJson()) {
                return response()->json(['message' => 'Connector not found'], 404);
            }
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=connector_not_found');
        }

        $client = new GoogleClient();
        $client->setClientId(env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));

        $token = $client->fetchAccessTokenWithAuthCode($code);
        
        \Log::info('Token exchange result', [
            'has_error' => isset($token['error']),
            'error' => $token['error'] ?? null,
            'has_access_token' => isset($token['access_token']),
            'has_refresh_token' => isset($token['refresh_token'])
        ]);
        
        if (isset($token['error'])) {
            \Log::error('OAuth token exchange failed', ['error' => $token['error']]);
            if ($request->isJson()) {
                return response()->json(['message' => 'OAuth error', 'error' => $token['error']], 400);
            }
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=oauth_error');
        }

        $connector->encrypted_tokens = encrypt(json_encode($token));
        $connector->status = 'connected';
        $connector->save();

        \Log::info('Connector updated successfully', [
            'connector_id' => $connector->id,
            'status' => $connector->status,
            'has_tokens' => !empty($connector->encrypted_tokens)
        ]);

        // ❌ REMOVED AUTO-TRIGGER: User must manually click "Sync" button
        // IngestConnectorJob::dispatch($connector->id, $connector->org_id)->onQueue('default');

        if ($request->isJson()) {
            return response()->json([
                'message' => 'Google Drive connected',
                'connector' => $connector,
            ]);
        }

        // For GET requests (Google redirect), redirect to frontend with success
        return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?success=google_drive_connected');
    }

    /**
     * Get Slack OAuth URL
     */
    public function getSlackAuthUrl(Request $request)
    {
        $slack = new \App\Services\SlackService();

        // Check if Slack connector already exists for this organization
        $existingConnector = Connector::where('org_id', $request->user()->org_id)
            ->where('type', 'slack')
            ->first();

        if ($existingConnector) {
            // Update existing connector if it's disconnected
            if ($existingConnector->status === 'disconnected') {
                $connector = $existingConnector;
            } else {
                return response()->json(['message' => 'Slack is already connected for this organization'], 409);
            }
        } else {
            // Create a new connector record
            $connector = Connector::create([
                'id' => (string) Str::uuid(),
                'org_id' => $request->user()->org_id,
                'type' => 'slack',
                'label' => 'Slack',
                'status' => 'disconnected',
            ]);
        }

        // State can include connector id to reconcile on callback
        $state = base64_encode(json_encode(['connector_id' => $connector->id]));

        return response()->json([
            'connector_id' => $connector->id,
            'url' => $slack->getAuthUrl($state),
        ]);
    }

    /**
     * Handle Slack OAuth callback
     */
    public function handleSlackCallback(Request $request)
    {
        \Log::info('Slack OAuth callback called', [
            'method' => $request->method(),
            'query_params' => $request->query(),
        ]);

        $code = (string) $request->query('code', '');
        $stateB64 = (string) $request->query('state', '');
        
        if ($code === '' || $stateB64 === '') {
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=missing_code_or_state');
        }

        $state = json_decode(base64_decode($stateB64), true) ?: [];
        $connectorId = $state['connector_id'] ?? null;
        
        if (!$connectorId) {
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=invalid_state');
        }

        // Find the connector
        $connector = Connector::find($connectorId);
        if (!$connector) {
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=connector_not_found');
        }

        try {
            $slack = new \App\Services\SlackService();
            
            // Exchange code for access token
            $tokenData = $slack->exchangeCode($code);
            
            \Log::info('Slack OAuth token exchange successful', [
                'connector_id' => $connector->id,
                'team_id' => $tokenData['team_id'],
                'team_name' => $tokenData['team_name'],
            ]);

            // Get team info
            $teamInfo = $slack->getTeamInfo($tokenData['access_token']);

            // Store tokens in encrypted_tokens column (like Google Drive and Dropbox)
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

            \Log::info('Slack connector saved successfully', [
                'connector_id' => $connector->id,
                'status' => $connector->status,
            ]);

        } catch (\Exception $e) {
            \Log::error('Slack OAuth failed', [
                'connector_id' => $connector->id,
                'error' => $e->getMessage(),
            ]);

            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=oauth_exchange_failed');
        }

        return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?success=slack_connected');
    }
}


