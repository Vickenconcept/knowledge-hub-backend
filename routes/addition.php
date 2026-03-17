<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Addition\ModuleController;
use App\Http\Controllers\Addition\V1\ContentStudioV1Controller;

Route::prefix('addition')->group(function () {
    // Public health endpoint for Addition module wiring checks.
    Route::get('health', [ModuleController::class, 'health']);

    Route::prefix('v1')->group(function () {
        Route::get('health', [ContentStudioV1Controller::class, 'health']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('content/overview', [ContentStudioV1Controller::class, 'contentOverview'])->middleware('throttle:30,1');
            Route::get('content/runs', [ContentStudioV1Controller::class, 'contentRuns'])->middleware('throttle:30,1');
            Route::get('content/runs/{id}', [ContentStudioV1Controller::class, 'contentRunShow'])->middleware('throttle:30,1');
            Route::post('content/generate', [ContentStudioV1Controller::class, 'contentGenerate'])->middleware('throttle:15,1');
            Route::post('course/coach', [ContentStudioV1Controller::class, 'courseCoach'])->middleware('throttle:30,1');
            Route::post('pdf/chat', [ContentStudioV1Controller::class, 'pdfChat'])->middleware('throttle:30,1');
            Route::post('summary/generate', [ContentStudioV1Controller::class, 'summaryGenerate'])->middleware('throttle:20,1');
            Route::post('strategy/plan', [ContentStudioV1Controller::class, 'strategyPlan'])->middleware('throttle:20,1');
        });
    });
});
