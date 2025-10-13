<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
    
    /**
     * Create a Stripe setup intent for saving payment method
     */
    public function createSetupIntent(Request $request)
    {
        try {
            $user = $request->user();
            $stripe = new \App\Services\StripeService();
            
            // Get or create Stripe customer
            $org = \App\Models\Organization::find($user->org_id);
            $billing = DB::table('organization_billing')
                ->where('org_id', $user->org_id)
                ->first();
            
            $stripeCustomerId = $billing->payment_provider_customer_id ?? null;
            
            if (!$stripeCustomerId) {
                $customerResult = $stripe->createCustomer(
                    $user->email,
                    $org->name,
                    $user->org_id
                );
                
                if (!$customerResult['success']) {
                    return response()->json([
                        'error' => 'Failed to create customer: ' . $customerResult['error'],
                    ], 500);
                }
                
                $stripeCustomerId = $customerResult['customer_id'];
                
                // Update billing record
                DB::table('organization_billing')
                    ->where('org_id', $user->org_id)
                    ->update([
                        'payment_provider_customer_id' => $stripeCustomerId,
                        'updated_at' => now(),
                    ]);
            }
            
            // Create setup intent
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $setupIntent = \Stripe\SetupIntent::create([
                'customer' => $stripeCustomerId,
                'payment_method_types' => ['card'],
            ]);
            
            return response()->json([
                'clientSecret' => $setupIntent->client_secret,
                'customerId' => $stripeCustomerId,
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating setup intent', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to create setup intent',
            ], 500);
        }
    }
    
    /**
     * Process payment for plan upgrade
     */
    public function processPayment(Request $request)
    {
        $request->validate([
            'tier_name' => 'required|string',
            'payment_method_id' => 'required|string',
            'customer_id' => 'nullable|string',
        ]);
        
        try {
            $user = $request->user();
            $org = \App\Models\Organization::find($user->org_id);
            
            // Process payment
            $result = SubscriptionService::processPayment(
                $user->org_id,
                $request->tier_name,
                $request->payment_method_id,
                $user->email,
                $org->name,
                $request->customer_id
            );
            
            if (!$result['success']) {
                return response()->json([
                    'error' => $result['error'] ?? 'Payment failed',
                ], 400);
            }
            
            // Change plan after successful payment
            // Pass 'stripe' as payment method since we just successfully charged
            $planResult = SubscriptionService::changePlan($user->org_id, $request->tier_name, 'stripe');
            
            return response()->json([
                'success' => true,
                'message' => 'Payment successful and plan upgraded',
                'transaction_id' => $result['transaction_id'],
                'amount' => $result['amount'],
                'subscription' => $planResult,
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing payment', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Payment processing failed',
            ], 500);
        }
    }
}

