<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Subscription Service
 * Manages organization subscriptions to pricing tiers
 */
class SubscriptionService
{
    /**
     * Subscribe organization to a pricing tier
     * This is called when org is created or when they upgrade/downgrade
     */
    public static function subscribeToPlan(
        string $orgId,
        string $tierName,
        string $billingCycle = 'monthly',
        ?string $paymentMethod = null,
        ?string $paymentProviderId = null
    ): array {
        // Get the pricing tier
        $tier = DB::table('pricing_tiers')
            ->where('name', $tierName)
            ->where('is_active', true)
            ->first();
        
        if (!$tier) {
            throw new \Exception("Pricing tier '{$tierName}' not found");
        }
        
        // Check if organization already has billing setup
        $existingBilling = DB::table('organization_billing')
            ->where('org_id', $orgId)
            ->first();
        
        $now = now();
        $periodStart = $now->startOfMonth()->toDateString();
        $periodEnd = $now->endOfMonth()->toDateString();
        
        if ($existingBilling) {
            // Update existing subscription
            DB::table('organization_billing')
                ->where('org_id', $orgId)
                ->update([
                    'pricing_tier_id' => $tier->id,
                    'billing_cycle' => $billingCycle,
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                    'status' => 'active',
                    'payment_method' => $paymentMethod,
                    'payment_provider_customer_id' => $paymentProviderId,
                    'updated_at' => $now,
                ]);
            
            Log::info('Subscription updated', [
                'org_id' => $orgId,
                'tier' => $tierName,
                'billing_cycle' => $billingCycle,
            ]);
        } else {
            // Create new subscription
            DB::table('organization_billing')->insert([
                'id' => Str::uuid(),
                'org_id' => $orgId,
                'pricing_tier_id' => $tier->id,
                'billing_cycle' => $billingCycle,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'status' => 'active',
                'payment_method' => $paymentMethod,
                'payment_provider_customer_id' => $paymentProviderId,
                'alert_threshold_percent' => 80.00,
                'auto_suspend_on_limit' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            
            Log::info('New subscription created', [
                'org_id' => $orgId,
                'tier' => $tierName,
                'billing_cycle' => $billingCycle,
            ]);
        }
        
        return [
            'success' => true,
            'tier' => $tierName,
            'monthly_fee' => $tier->monthly_base_fee,
            'billing_cycle' => $billingCycle,
        ];
    }
    
    /**
     * Auto-assign new organization to Free tier
     */
    public static function assignFreeTier(string $orgId): void
    {
        try {
            self::subscribeToPlan($orgId, 'free', 'monthly');
            Log::info('Organization auto-assigned to Free tier', ['org_id' => $orgId]);
        } catch (\Exception $e) {
            Log::error('Failed to assign free tier', [
                'org_id' => $orgId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Upgrade/Downgrade subscription
     */
    public static function changePlan(
        string $orgId,
        string $newTierName,
        ?string $paymentMethod = null
    ): array {
        // Get current subscription
        $currentBilling = DB::table('organization_billing')
            ->join('pricing_tiers', 'organization_billing.pricing_tier_id', '=', 'pricing_tiers.id')
            ->where('organization_billing.org_id', $orgId)
            ->select('pricing_tiers.name as current_tier', 'pricing_tiers.display_name')
            ->first();
        
        $currentTier = $currentBilling->current_tier ?? 'free';
        
        // Validate the change
        $validation = self::validatePlanChange($orgId, $currentTier, $newTierName);
        
        if (!$validation['allowed']) {
            throw new \Exception($validation['reason']);
        }
        
        // Check if this is an upgrade or downgrade
        $isUpgrade = self::isUpgrade($currentTier, $newTierName);
        
        // If upgrading to paid tier, require payment method
        if ($isUpgrade && $newTierName !== 'free' && !$paymentMethod) {
            throw new \Exception('Payment method required for paid plans');
        }
        
        // Change the subscription
        $result = self::subscribeToPlan($orgId, $newTierName, 'monthly', $paymentMethod);
        
        // Log the change
        Log::info('Plan changed', [
            'org_id' => $orgId,
            'from' => $currentTier,
            'to' => $newTierName,
            'is_upgrade' => $isUpgrade,
        ]);
        
        return array_merge($result, [
            'previous_tier' => $currentTier,
            'is_upgrade' => $isUpgrade,
            'change_type' => $isUpgrade ? 'upgrade' : 'downgrade',
        ]);
    }
    
    /**
     * Validate if plan change is allowed
     */
    private static function validatePlanChange(string $orgId, string $currentTier, string $newTier): array
    {
        // Can't change to same tier
        if ($currentTier === $newTier) {
            return [
                'allowed' => false,
                'reason' => 'Already on this plan',
            ];
        }
        
        // Check if downgrading would exceed new limits
        if (self::isDowngrade($currentTier, $newTier)) {
            $newTierLimits = DB::table('pricing_tiers')
                ->where('name', $newTier)
                ->first();
            
            // Check document count
            $documentCount = DB::table('documents')->where('org_id', $orgId)->count();
            if ($newTierLimits->max_documents && $documentCount > $newTierLimits->max_documents) {
                return [
                    'allowed' => false,
                    'reason' => "You have {$documentCount} documents but {$newTier} tier allows only {$newTierLimits->max_documents}. Please delete some documents first.",
                ];
            }
            
            // Check user count
            $userCount = DB::table('users')->where('org_id', $orgId)->count();
            if ($newTierLimits->max_users && $userCount > $newTierLimits->max_users) {
                return [
                    'allowed' => false,
                    'reason' => "You have {$userCount} users but {$newTier} tier allows only {$newTierLimits->max_users}. Please remove some users first.",
                ];
            }
        }
        
        return [
            'allowed' => true,
            'reason' => null,
        ];
    }
    
    /**
     * Check if moving to higher tier
     */
    private static function isUpgrade(string $currentTier, string $newTier): bool
    {
        $tierOrder = ['free' => 0, 'starter' => 1, 'pro' => 2, 'enterprise' => 3];
        return ($tierOrder[$newTier] ?? 0) > ($tierOrder[$currentTier] ?? 0);
    }
    
    /**
     * Check if moving to lower tier
     */
    private static function isDowngrade(string $currentTier, string $newTier): bool
    {
        return !self::isUpgrade($currentTier, $newTier) && $currentTier !== $newTier;
    }
    
    /**
     * Cancel subscription (move to Free tier)
     */
    public static function cancelSubscription(string $orgId): array
    {
        try {
            $result = self::subscribeToPlan($orgId, 'free', 'monthly');
            
            Log::info('Subscription cancelled, moved to Free tier', [
                'org_id' => $orgId,
            ]);
            
            return array_merge($result, [
                'message' => 'Subscription cancelled. You are now on the Free plan.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cancel subscription', [
                'org_id' => $orgId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Get subscription status and available upgrades
     */
    public static function getSubscriptionOptions(string $orgId): array
    {
        // Get current subscription
        $currentBilling = DB::table('organization_billing')
            ->join('pricing_tiers', 'organization_billing.pricing_tier_id', '=', 'pricing_tiers.id')
            ->where('organization_billing.org_id', $orgId)
            ->select('pricing_tiers.*', 'organization_billing.status', 'organization_billing.billing_cycle')
            ->first();
        
        $currentTierName = $currentBilling->name ?? 'free';
        
        // Get all available tiers
        $allTiers = DB::table('pricing_tiers')
            ->where('is_active', true)
            ->orderByRaw("FIELD(name, 'free', 'starter', 'pro', 'enterprise')")
            ->get();
        
        // Check current usage
        $usage = [
            'documents' => DB::table('documents')->where('org_id', $orgId)->count(),
            'users' => DB::table('users')->where('org_id', $orgId)->count(),
            'chat_queries_this_month' => DB::table('cost_tracking')
                ->where('org_id', $orgId)
                ->where('operation_type', 'chat')
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
        ];
        
        // Mark which tiers are available
        $tiersWithAvailability = $allTiers->map(function($tier) use ($currentTierName, $usage, $orgId) {
            $validation = self::validatePlanChange($orgId, $currentTierName, $tier->name);
            
            return [
                'id' => $tier->id,
                'name' => $tier->name,
                'display_name' => $tier->display_name,
                'description' => $tier->description,
                'monthly_base_fee' => (float) $tier->monthly_base_fee,
                'cost_markup_multiplier' => (float) $tier->cost_markup_multiplier,
                'max_users' => $tier->max_users,
                'max_documents' => $tier->max_documents,
                'max_chat_queries_per_month' => $tier->max_chat_queries_per_month,
                'max_storage_gb' => $tier->max_storage_gb,
                'custom_connectors' => (bool) $tier->custom_connectors,
                'priority_support' => (bool) $tier->priority_support,
                'api_access' => (bool) $tier->api_access,
                'white_label' => (bool) $tier->white_label,
                'is_current' => $tier->name === $currentTierName,
                'is_upgrade' => self::isUpgrade($currentTierName, $tier->name),
                'is_downgrade' => self::isDowngrade($currentTierName, $tier->name),
                'can_switch' => $validation['allowed'],
                'switch_blocked_reason' => $validation['reason'],
            ];
        });
        
        return [
            'current_tier' => $currentTierName,
            'current_tier_display' => $currentBilling->display_name ?? 'Free',
            'billing_cycle' => $currentBilling->billing_cycle ?? 'monthly',
            'status' => $currentBilling->status ?? 'active',
            'available_tiers' => $tiersWithAvailability->toArray(),
            'current_usage' => $usage,
        ];
    }
    
    /**
     * PAYMENT GATEWAY INTEGRATION HOOKS
     * These will be implemented when you choose a payment provider
     */
    
    /**
     * Process payment for subscription
     * To be implemented with Stripe/PayPal
     */
    public static function processPayment(
        string $orgId,
        string $tierName,
        string $paymentMethod,
        array $paymentDetails
    ): array {
        // TODO: Integrate with payment gateway
        // For now, just mark as paid
        
        Log::info('Payment processing placeholder', [
            'org_id' => $orgId,
            'tier' => $tierName,
            'payment_method' => $paymentMethod,
        ]);
        
        return [
            'success' => true,
            'transaction_id' => 'PENDING_GATEWAY_INTEGRATION',
            'amount' => 0,
            'message' => 'Payment gateway integration pending',
        ];
    }
    
    /**
     * Setup payment method for organization
     * To be implemented with Stripe/PayPal
     */
    public static function setupPaymentMethod(
        string $orgId,
        string $provider, // 'stripe', 'paypal', etc.
        array $paymentData
    ): array {
        // TODO: Integrate with payment gateway
        
        Log::info('Payment method setup placeholder', [
            'org_id' => $orgId,
            'provider' => $provider,
        ]);
        
        return [
            'success' => true,
            'provider' => $provider,
            'customer_id' => 'PENDING_GATEWAY_INTEGRATION',
        ];
    }
    
    /**
     * Process monthly recurring payment
     * Called by cron job at end of each month
     */
    public static function processRecurringPayment(string $orgId): array
    {
        // Generate invoice for current month
        $invoice = BillingService::generateInvoice(
            $orgId,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString()
        );
        
        // Get payment method on file
        $billing = DB::table('organization_billing')
            ->where('org_id', $orgId)
            ->first();
        
        if (!$billing || !$billing->payment_method) {
            // No payment method - mark invoice as pending
            DB::table('invoices')
                ->where('id', $invoice['invoice_id'])
                ->update(['status' => 'issued']);
            
            return [
                'success' => false,
                'reason' => 'no_payment_method',
                'invoice_id' => $invoice['invoice_id'],
            ];
        }
        
        // TODO: Charge payment method via gateway
        // For now, just mark as draft
        
        Log::info('Recurring payment placeholder', [
            'org_id' => $orgId,
            'invoice_id' => $invoice['invoice_id'],
            'amount' => $invoice['total_amount'],
        ]);
        
        return [
            'success' => true,
            'invoice_id' => $invoice['invoice_id'],
            'amount' => $invoice['total_amount'],
            'payment_status' => 'pending_gateway',
        ];
    }
    
    /**
     * Get upgrade recommendations based on usage
     */
    public static function getUpgradeRecommendation(string $orgId): ?array
    {
        $billing = BillingService::calculateBilling(
            $orgId,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString()
        );
        
        $currentTier = $billing['tier_name'];
        
        // Check if hitting limits
        if (!empty($billing['limits']['exceeded']) || !empty($billing['limits']['warnings'])) {
            $tierOrder = ['free' => 'starter', 'starter' => 'pro', 'pro' => 'enterprise'];
            $recommendedTier = $tierOrder[strtolower($currentTier)] ?? null;
            
            if ($recommendedTier) {
                $nextTier = DB::table('pricing_tiers')
                    ->where('name', $recommendedTier)
                    ->first();
                
                return [
                    'should_upgrade' => true,
                    'current_tier' => $currentTier,
                    'recommended_tier' => $nextTier->display_name,
                    'recommended_tier_name' => $recommendedTier,
                    'reason' => 'You are approaching or exceeding your current plan limits',
                    'new_monthly_fee' => (float) $nextTier->monthly_base_fee,
                    'benefits' => [
                        "Increase to {$nextTier->max_chat_queries_per_month} queries/month",
                        "Store up to {$nextTier->max_documents} documents",
                        $nextTier->priority_support ? 'Priority support' : null,
                    ],
                ];
            }
        }
        
        return null; // No upgrade needed
    }
}

