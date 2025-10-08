<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * Get current subscription and available plans
     */
    public function getOptions(Request $request)
    {
        try {
            $user = $request->user();
            $options = SubscriptionService::getSubscriptionOptions($user->org_id);
            
            return response()->json($options);
        } catch (\Exception $e) {
            Log::error('Error fetching subscription options', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch subscription options',
            ], 500);
        }
    }
    
    /**
     * Change subscription plan
     */
    public function changePlan(Request $request)
    {
        try {
            $validated = $request->validate([
                'tier_name' => 'required|string|in:free,starter,pro,enterprise',
                'payment_method' => 'nullable|string',
                'payment_token' => 'nullable|string', // For Stripe/PayPal token
            ]);
            
            $user = $request->user();
            
            // Change the plan
            $result = SubscriptionService::changePlan(
                $user->org_id,
                $validated['tier_name'],
                $validated['payment_method'] ?? null
            );
            
            // TODO: Process payment if upgrading to paid tier
            // if ($result['is_upgrade'] && $validated['tier_name'] !== 'free') {
            //     $payment = SubscriptionService::processPayment(
            //         $user->org_id,
            //         $validated['tier_name'],
            //         $validated['payment_method'],
            //         ['token' => $validated['payment_token']]
            //     );
            // }
            
            return response()->json([
                'message' => $result['is_upgrade'] 
                    ? 'Successfully upgraded to ' . $validated['tier_name']
                    : 'Successfully changed to ' . $validated['tier_name'],
                'subscription' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Error changing plan', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
    
    /**
     * Get upgrade recommendation
     */
    public function getUpgradeRecommendation(Request $request)
    {
        try {
            $user = $request->user();
            $recommendation = SubscriptionService::getUpgradeRecommendation($user->org_id);
            
            return response()->json([
                'recommendation' => $recommendation,
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting upgrade recommendation', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to get recommendation',
            ], 500);
        }
    }
    
    /**
     * Cancel subscription (downgrade to Free)
     */
    public function cancelSubscription(Request $request)
    {
        try {
            $user = $request->user();
            $result = SubscriptionService::cancelSubscription($user->org_id);
            
            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Error cancelling subscription', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to cancel subscription',
            ], 500);
        }
    }
}

