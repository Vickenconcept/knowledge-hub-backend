<?php

namespace App\Http\Controllers;

use App\Models\Connector;
use App\Models\IngestJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Jobs\IngestConnectorJob;

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
}


