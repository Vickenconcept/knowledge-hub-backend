<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Organization;
use App\Models\User;
use App\Models\Document;
use App\Models\Chunk;

class AdminController extends Controller
{
    public function stats(Request $request)
    {
        if (($request->user()->role ?? 'user') !== 'admin') {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        return response()->json([
            'org_count' => Organization::count(),
            'user_count' => User::count(),
            'document_count' => Document::where(function($query) {
                $query->where('doc_type', '!=', 'guide')
                      ->orWhereNull('doc_type');
            })->count(),
            'chunk_count' => Chunk::count(),
        ]);
    }
}


