<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Addition\ModuleController;
use App\Http\Controllers\Addition\V1\AdditionV1Controller;

Route::prefix('addition')->group(function () {
    // Public health endpoint for Addition module wiring checks.
    Route::get('health', [ModuleController::class, 'health']);

    Route::prefix('v1')->group(function () {
        Route::get('health', [AdditionV1Controller::class, 'health']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('content/generate', [AdditionV1Controller::class, 'contentGenerate'])->middleware('throttle:15,1');
            Route::post('course/coach', [AdditionV1Controller::class, 'courseCoach'])->middleware('throttle:30,1');
            Route::post('pdf/chat', [AdditionV1Controller::class, 'pdfChat'])->middleware('throttle:30,1');
            Route::post('summary/generate', [AdditionV1Controller::class, 'summaryGenerate'])->middleware('throttle:20,1');
            Route::post('strategy/plan', [AdditionV1Controller::class, 'strategyPlan'])->middleware('throttle:20,1');
        });
    });
});
