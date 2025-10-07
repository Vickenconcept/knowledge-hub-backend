<?php

namespace App\Http\Controllers;

use App\Models\Connector;
use App\Models\IngestJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Jobs\IngestConnectorJob;
use Google\Client as GoogleClient;

class ConnectorController extends Controller
{
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
            
            \Log::info('Checking running job for connector', [
                'connector_id' => $connector->id,
                'type' => $connector->type,
                'current_status' => $connector->status,
                'has_running_job' => !is_null($runningJob),
                'job_status' => $runningJob?->status ?? 'no job'
            ]);
            
            if ($runningJob) {
                \Log::info('Setting connector status to syncing', [
                    'connector_id' => $connector->id,
                    'job_id' => $runningJob->id,
                    'job_status' => $runningJob->status
                ]);
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

        return response()->json([
            'has_job' => true,
            'job_id' => $job->id,
            'status' => $job->status,
            'stats' => $job->stats,
            'created_at' => $job->created_at,
            'finished_at' => $job->finished_at,
            'progress_percentage' => isset($job->stats['total_files']) && $job->stats['total_files'] > 0
                ? round(($job->stats['processed_files'] / $job->stats['total_files']) * 100, 1)
                : 0,
        ]);
    }

    public function startIngest(Request $request, string $id)
    {
        \Log::info('=== SYNC STARTED ===', [
            'connector_id' => $id,
            'user_id' => $request->user()->id,
            'org_id' => $request->user()->org_id
        ]);

        $connector = Connector::where('id', $id)
            ->where('org_id', $request->user()->org_id)
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

        // Optionally kick off ingestion immediately
        IngestConnectorJob::dispatch($connector->id, $connector->org_id)->onQueue('default');

        if ($request->isJson()) {
            return response()->json([
                'message' => 'Google Drive connected',
                'connector' => $connector,
            ]);
        }

        // For GET requests (Google redirect), redirect to frontend with success
        return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?success=google_drive_connected');
    }
}


