<?php

namespace App\Http\Controllers\Core;

use Illuminate\Http\Request;
use App\Models\Organization;
use App\Models\User;
use App\Models\Document;
use App\Models\Chunk;

class AdminController extends Controller
{
    public function stats(Request $request)
    {
        // if (($request->user()->role ?? 'user') !== 'admin') {
        //     return response()->json(['error' => 'Forbidden'], 403);
        // }
        $user = $request->user();
        $isSuperAdmin = $user && $user->role === 'super_admin';
        $orgId = $user?->org_id;

        $orgCount = $isSuperAdmin
            ? Organization::count()
            : ($orgId ? Organization::where('id', $orgId)->count() : 0);

        $userCount = User::when(!$isSuperAdmin && $orgId, function ($query) use ($orgId) {
                $query->where('org_id', $orgId);
            })
            ->count();

        $documentCount = Document::where(function ($query) {
                $query->where('doc_type', '!=', 'guide')
                      ->orWhereNull('doc_type');
            })
            ->when(!$isSuperAdmin && $orgId, function ($query) use ($orgId) {
                $query->where('org_id', $orgId);
            })
            ->count();

        $chunkCount = Chunk::when(!$isSuperAdmin && $orgId, function ($query) use ($orgId) {
                $query->where('org_id', $orgId);
            })
            ->count();

        return response()->json([
            'org_count' => $orgCount,
            'user_count' => $userCount,
            'document_count' => $documentCount,
            'chunk_count' => $chunkCount,
        ]);
    }

    public function registrationSources(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = $user && $user->role === 'super_admin';
        $orgId = $user?->org_id;

        $query = User::query();
        if (!$isSuperAdmin && $orgId) {
            $query->where('org_id', $orgId);
        }

        $sources = $query
            ->selectRaw("COALESCE(NULLIF(registered_from, ''), 'unknown') as source")
            ->selectRaw('COUNT(*) as total')
            ->groupBy('source')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'total_users' => $sources->sum('total'),
            'sources' => $sources,
        ]);
    }
}


