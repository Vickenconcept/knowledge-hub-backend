<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConnectorController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\IngestJobController;

Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/login', [AuthController::class, 'login']);

Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'time' => now()->toISOString(),
    ]);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Chat / Search
    Route::post('chat', [ChatController::class, 'ask']);
    Route::post('search', [ChatController::class, 'search']);
    Route::post('feedback', [ChatController::class, 'feedback']);

    // Connectors
    Route::get('orgs/{org}/connectors', [ConnectorController::class, 'index']);
    Route::post('orgs/{org}/connectors', [ConnectorController::class, 'create']);
    Route::post('connectors/{id}/oauth/callback', [ConnectorController::class, 'oauthCallback']);
    Route::post('connectors/{id}/start-ingest', [ConnectorController::class, 'startIngest']);

    // Documents
    Route::get('documents', [DocumentController::class, 'index']);
    Route::get('documents/{id}', [DocumentController::class, 'show']);
    Route::get('documents/{id}/chunks', [DocumentController::class, 'chunks']);
    Route::post('documents/{id}/reindex', [DocumentController::class, 'reindex']);
    Route::delete('documents/{id}', [DocumentController::class, 'destroy']);
    Route::post('documents/upload', [DocumentController::class, 'upload']);

    // Admin
    Route::get('admin/stats', [AdminController::class, 'stats']);

    // Ingest jobs
    Route::get('ingest-jobs/{id}', [IngestJobController::class, 'show']);
});
