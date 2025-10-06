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
        $connectors = Connector::where('org_id', $orgId)->get();
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

    public function startIngest(Request $request, string $id)
    {
        $connector = Connector::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();
        $job = IngestJob::create([
            'org_id' => $connector->org_id,
            'connector_id' => $connector->id,
            'status' => 'queued',
            'stats' => ['docs' => 0, 'chunks' => 0, 'errors' => 0],
        ]);

        IngestConnectorJob::dispatch($connector->id, $request->user()->org_id)->onQueue('default');

        return response()->json([
            'message' => 'Ingestion started',
            'job_id' => $job->id,
        ]);
    }

    public function getGoogleDriveAuthUrl(Request $request)
    {
        $client = new GoogleClient();
        $client->setClientId(env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->addScope([
            'https://www.googleapis.com/auth/drive.readonly',
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
        $code = (string) $request->query('code', '');
        $stateB64 = (string) $request->query('state', '');
        
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

        // For GET requests, we need to authenticate the user differently
        if ($request->isMethod('GET')) {
            // Get the user from the connector's organization
            $connector = Connector::find($connectorId);
            if (!$connector) {
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=connector_not_found');
            }
            
            $user = \App\Models\User::where('org_id', $connector->org_id)->first();
            if (!$user) {
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=user_not_found');
            }
        } else {
            $connector = Connector::where('id', $connectorId)
                ->where('org_id', $request->user()->org_id)
                ->firstOrFail();
        }

        $client = new GoogleClient();
        $client->setClientId(env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));

        $token = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            if ($request->isJson()) {
                return response()->json(['message' => 'OAuth error', 'error' => $token['error']], 400);
            }
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/connectors?error=oauth_error');
        }

        $connector->encrypted_tokens = encrypt(json_encode($token));
        $connector->status = 'connected';
        $connector->save();

        // Optionally kick off ingestion immediately
        if ($request->isMethod('POST')) {
            IngestConnectorJob::dispatch($connector->id, $request->user()->org_id)->onQueue('default');
        } else {
            IngestConnectorJob::dispatch($connector->id, $connector->org_id)->onQueue('default');
        }

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


