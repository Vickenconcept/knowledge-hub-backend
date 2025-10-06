<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\IngestJob;

class IngestJobController extends Controller
{
    public function show(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $job = IngestJob::where('id', $id)->where('org_id', $orgId)->first();
        if (!$job) return response()->json(['error' => 'Not found'], 404);
        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'stats' => $job->stats,
            'error_log' => $job->error_log,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at,
        ]);
    }
}


