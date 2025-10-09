<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConnectorController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\IngestJobController;
use App\Http\Controllers\DropboxController;
use App\Http\Controllers\CostTrackingController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\PricingTierController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\StripeWebhookController;

Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/login', [AuthController::class, 'login']);

// Stripe Webhook (no auth required)
Route::post('webhooks/stripe', [StripeWebhookController::class, 'handleWebhook']);



Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'time' => now()->toISOString(),
    ]);
});

Route::post('test-login', function (Request $request) {
    return response()->json([
        'message' => 'Test endpoint working',
        'method' => $request->method(),
        'content_type' => $request->header('Content-Type'),
        'origin' => $request->header('Origin'),
        'body' => $request->all(),
    ]);
});

// Simple login test endpoint
Route::post('test-auth', function (Request $request) {
    $email = $request->input('email');
    $password = $request->input('password');
    
    $user = \App\Models\User::where('email', $email)->first();
    
    if (!$user) {
        return response()->json(['error' => 'User not found'], 404);
    }
    
    $passwordMatch = \Illuminate\Support\Facades\Hash::check($password, $user->password);
    
    return response()->json([
        'email' => $email,
        'password_length' => strlen($password),
        'user_found' => !!$user,
        'user_name' => $user->name,
        'password_match' => $passwordMatch,
        'success' => $passwordMatch
    ]);
});

Route::options('{any}', function () {
    return response('', 200);
})->where('any', '.*');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Chat / Search
    Route::post('chat', [ChatController::class, 'ask']);
    Route::post('search', [ChatController::class, 'search']);
    Route::post('feedback', [ChatController::class, 'feedback']);
    
    // Conversations
    Route::get('conversations', [ChatController::class, 'getConversations']);
    Route::get('conversations/{id}', [ChatController::class, 'getConversation']);
    Route::delete('conversations/{id}', [ChatController::class, 'deleteConversation']);
    Route::patch('conversations/{id}/style', [ChatController::class, 'updateConversationStyle']);
    Route::get('response-styles', [ChatController::class, 'getResponseStyles']);
    Route::patch('user/preferences', [ChatController::class, 'updateUserPreferences']);

    // Connectors
    Route::get('orgs/{org}/connectors', [ConnectorController::class, 'index']);
    Route::post('orgs/{org}/connectors', [ConnectorController::class, 'create']);
    Route::post('connectors/{id}/oauth/callback', [ConnectorController::class, 'oauthCallback']);
    Route::post('connectors/{id}/start-ingest', [ConnectorController::class, 'startIngest']);
    Route::post('connectors/{id}/stop-sync', [ConnectorController::class, 'stopSync']);
    Route::post('connectors/{id}/disconnect', [ConnectorController::class, 'disconnect']);
    Route::get('connectors/{connectorId}/job-status', [ConnectorController::class, 'getJobStatus']);

    // Google Drive OAuth
    Route::get('connectors/google-drive/auth-url', [ConnectorController::class, 'getGoogleDriveAuthUrl']);
    
    // Dropbox Integration
    Route::get('connectors/dropbox/auth-url', [DropboxController::class, 'authUrl']);
    Route::post('connectors/dropbox/{id}/disconnect', [DropboxController::class, 'disconnect']);
    
    // Slack Integration
    Route::get('connectors/slack/auth-url', [ConnectorController::class, 'getSlackAuthUrl']);

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
    
    // Cost Tracking
    Route::get('cost-tracking/stats', [CostTrackingController::class, 'getStats']);
    Route::get('cost-tracking/org-breakdown', [CostTrackingController::class, 'getOrgBreakdown']);
    Route::get('cost-tracking/history', [CostTrackingController::class, 'getHistory']);
    Route::post('cost-tracking/estimate', [CostTrackingController::class, 'estimateCost']);
    
    // Billing & Revenue
    Route::get('billing/current', [BillingController::class, 'getCurrentBilling']);
    Route::get('billing/invoices', [BillingController::class, 'getInvoices']);
    Route::get('billing/invoices/{id}', [BillingController::class, 'getInvoice']);
    Route::get('billing/organization', [BillingController::class, 'getOrganizationBilling']);
    Route::get('billing/pricing-tiers', [BillingController::class, 'getPricingTiers']);
    Route::get('billing/revenue-summary', [BillingController::class, 'getRevenueSummary']);
    Route::post('billing/generate-invoice', [BillingController::class, 'generateInvoice']);
    
    // Subscription Management
    Route::get('subscription/options', [SubscriptionController::class, 'getOptions']);
    Route::post('subscription/change-plan', [SubscriptionController::class, 'changePlan']);
    Route::get('subscription/upgrade-recommendation', [SubscriptionController::class, 'getUpgradeRecommendation']);
    Route::post('subscription/cancel', [SubscriptionController::class, 'cancelSubscription']);
    
    // Stripe Payment
    Route::post('payment/setup-intent', [SubscriptionController::class, 'createSetupIntent']);
    Route::post('payment/process', [SubscriptionController::class, 'processPayment']);
    
    // Admin: Pricing Tier Management
    Route::get('admin/pricing-tiers', [PricingTierController::class, 'index']);
    Route::get('admin/pricing-tiers/{id}', [PricingTierController::class, 'show']);
    Route::post('admin/pricing-tiers', [PricingTierController::class, 'store']);
    Route::put('admin/pricing-tiers/{id}', [PricingTierController::class, 'update']);
    Route::post('admin/pricing-tiers/{id}/toggle', [PricingTierController::class, 'toggleActive']);
    Route::delete('admin/pricing-tiers/{id}', [PricingTierController::class, 'destroy']);
    
    // Team Management
    Route::get('team', [TeamController::class, 'index']);
    Route::post('team/invite', [TeamController::class, 'invite']);
    Route::put('team/{id}/role', [TeamController::class, 'updateRole']);
    Route::delete('team/{id}', [TeamController::class, 'remove']);
    
    // DEBUG: Check running jobs
    Route::get('debug/running-jobs', function() {
        $jobs = \App\Models\IngestJob::whereIn('status', ['running', 'queued', 'processing_large_files'])
            ->get(['id', 'connector_id', 'org_id', 'status', 'created_at']);
        return response()->json(['jobs' => $jobs, 'count' => $jobs->count()]);
    });
});
