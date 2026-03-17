<?php

namespace App\Http\Controllers\Addition;

use Illuminate\Http\JsonResponse;

class ModuleController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'module' => 'addition',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
