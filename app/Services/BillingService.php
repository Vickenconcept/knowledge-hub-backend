<?php

namespace App\Services;

use App\Models\CostTracking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Billing Service
 * Calculates what to charge customers based on infrastructure costs + markup
 */
class BillingService
{
    /**
     * Calculate customer billing for a period
     * 
     * @param string $orgId
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public static function calculateBilling(string $orgId, string $startDate, string $endDate): array
    {
        // Get organization's pricing tier
        $orgBilling = DB::table('organization_billing')
            ->join('pricing_tiers', 'organization_billing.pricing_tier_id', '=', 'pricing_tiers.id')
            ->where('organization_billing.org_id', $orgId)
            ->select('pricing_tiers.*', 'organization_billing.*')
            ->first();
        
        if (!$orgBilling) {
            // Default to Free tier if no billing setup
            $orgBilling = DB::table('pricing_tiers')
                ->where('name', 'free')
                ->first();
        }
        
        // Get all costs for the period
        $costs = CostTracking::where('org_id', $orgId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();
        
        // Calculate infrastructure costs (what YOU pay)
        $infrastructureCost = $costs->sum('cost_usd');
        
        // Calculate costs by operation type
        $costsByOperation = [
            'openai_chat' => $costs->where('operation_type', 'chat')->sum('cost_usd'),
            'openai_embeddings' => $costs->where('operation_type', 'embedding')->sum('cost_usd'),
            'pinecone_queries' => $costs->where('operation_type', 'vector_query')->sum('cost_usd'),
            'pinecone_storage' => $costs->where('operation_type', 'vector_upsert')->sum('cost_usd'),
            'connector_usage' => 0, // File pulls are free but we track them
        ];
        
        // Calculate usage metrics
        $metrics = [
            'total_chat_queries' => $costs->where('operation_type', 'chat')->count(),
            'total_documents_indexed' => $costs->where('operation_type', 'document_ingestion')->count(), // ✅ Documents created
            'total_embeddings' => $costs->where('operation_type', 'embedding')->count(), // ✅ Chunks embedded
            'total_vector_queries' => $costs->where('operation_type', 'vector_query')->count(),
            'total_vector_upserts' => $costs->where('operation_type', 'vector_upsert')->count(),
            'total_file_pulls' => $costs->where('operation_type', 'file_pull')->count(),
            'current_documents_stored' => DB::table('documents')->where('org_id', $orgId)->count(),
            'total_operations' => $costs->count(),
        ];
        
        // Apply markup multiplier
        $markupMultiplier = $orgBilling->cost_markup_multiplier ?? 2.0;
        $usageCharge = $infrastructureCost * $markupMultiplier;
        $markupAmount = $usageCharge - $infrastructureCost;
        
        // Add base subscription fee
        $baseSubscriptionFee = $orgBilling->monthly_base_fee ?? 0;
        
        // Total amount to charge customer
        $totalAmount = $baseSubscriptionFee + $usageCharge;
        
        // Check if customer exceeded limits
        $limits = self::checkLimits($orgId, $orgBilling, $metrics);
        
        return [
            'org_id' => $orgId,
            'period_start' => $startDate,
            'period_end' => $endDate,
            'tier_name' => $orgBilling->display_name ?? 'Free',
            
            // What YOU pay (infrastructure costs)
            'infrastructure_cost' => round($infrastructureCost, 2),
            'costs_by_operation' => array_map(fn($v) => round($v, 2), $costsByOperation),
            
            // What CUSTOMER pays
            'base_subscription_fee' => round($baseSubscriptionFee, 2),
            'usage_charge' => round($usageCharge, 2),
            'markup_amount' => round($markupAmount, 2),
            'markup_multiplier' => $markupMultiplier,
            'total_amount' => round($totalAmount, 2),
            
            // Your profit
            'gross_profit' => round($totalAmount - $infrastructureCost, 2),
            'profit_margin_percent' => $totalAmount > 0 
                ? round((($totalAmount - $infrastructureCost) / $totalAmount) * 100, 2) 
                : 0,
            
            // Usage metrics
            'metrics' => $metrics,
            'limits' => $limits,
        ];
    }
    
    /**
     * Check if organization exceeded usage limits
     */
    private static function checkLimits(string $orgId, $tier, array $metrics): array
    {
        $exceeded = [];
        $warnings = [];
        
        // Check chat queries
        if ($tier->max_chat_queries_per_month && $metrics['total_chat_queries'] > $tier->max_chat_queries_per_month) {
            $exceeded[] = "Chat queries: {$metrics['total_chat_queries']} / {$tier->max_chat_queries_per_month}";
        } elseif ($tier->max_chat_queries_per_month && $metrics['total_chat_queries'] > ($tier->max_chat_queries_per_month * 0.8)) {
            $warnings[] = "Chat queries at 80%: {$metrics['total_chat_queries']} / {$tier->max_chat_queries_per_month}";
        }
        
        // Check documents (monthly ingestion from cost_tracking to prevent gaming)
        $currentDocumentCount = DB::table('documents')->where('org_id', $orgId)->count();
        $monthlyDocIngestion = DB::table('cost_tracking')
            ->where('org_id', $orgId)
            ->where('operation_type', 'document_ingestion')
            ->where('created_at', '>=', date('Y-m-01')) // This month
            ->count();
        
        if ($tier->max_documents && $monthlyDocIngestion > $tier->max_documents) {
            $exceeded[] = "Documents indexed this month: {$monthlyDocIngestion} / {$tier->max_documents} (Currently stored: {$currentDocumentCount})";
        } elseif ($tier->max_documents && $monthlyDocIngestion > ($tier->max_documents * 0.8)) {
            $warnings[] = "Documents at 80%: {$monthlyDocIngestion} / {$tier->max_documents}";
        }
        
        // Check users
        $userCount = DB::table('users')->where('org_id', $orgId)->count();
        if ($tier->max_users && $userCount > $tier->max_users) {
            $exceeded[] = "Users: {$userCount} / {$tier->max_users}";
        }
        
        return [
            'exceeded' => $exceeded,
            'warnings' => $warnings,
            'is_over_limit' => !empty($exceeded),
            'needs_upgrade' => !empty($exceeded) || !empty($warnings),
        ];
    }
    
    /**
     * Generate invoice for organization
     */
    public static function generateInvoice(string $orgId, string $startDate, string $endDate): array
    {
        $billing = self::calculateBilling($orgId, $startDate, $endDate);
        
        // Generate invoice number (INV-YYYY-MM-###)
        $month = date('Y-m', strtotime($endDate));
        $count = DB::table('invoices')
            ->where('invoice_number', 'like', "INV-{$month}-%")
            ->count();
        $invoiceNumber = sprintf("INV-%s-%03d", $month, $count + 1);
        
        // Create invoice record
        $invoiceId = \Illuminate\Support\Str::uuid();
        DB::table('invoices')->insert([
            'id' => $invoiceId,
            'org_id' => $orgId,
            'invoice_number' => $invoiceNumber,
            'period_start' => $startDate,
            'period_end' => $endDate,
            'infrastructure_cost' => $billing['infrastructure_cost'],
            'markup_amount' => $billing['markup_amount'],
            'base_subscription_fee' => $billing['base_subscription_fee'],
            'total_amount' => $billing['total_amount'],
            'total_chat_queries' => $billing['metrics']['total_chat_queries'],
            'total_documents' => DB::table('documents')->where('org_id', $orgId)->count(),
            'total_embeddings' => $billing['metrics']['total_embeddings'],
            'total_vector_queries' => $billing['metrics']['total_vector_queries'],
            'status' => 'draft',
            'due_date' => date('Y-m-d', strtotime($endDate . ' +15 days')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        Log::info('Invoice generated', [
            'invoice_number' => $invoiceNumber,
            'org_id' => $orgId,
            'total_amount' => $billing['total_amount'],
        ]);
        
        return array_merge($billing, [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'due_date' => date('Y-m-d', strtotime($endDate . ' +15 days')),
        ]);
    }
    
    /**
     * Get revenue summary for date range
     */
    public static function getRevenueSummary(string $startDate, string $endDate): array
    {
        // Get all invoices in period
        $invoices = DB::table('invoices')
            ->whereBetween('period_end', [$startDate, $endDate])
            ->get();
        
        // Calculate totals
        $totalRevenue = $invoices->sum('total_amount');
        $totalCosts = $invoices->sum('infrastructure_cost');
        $subscriptionRevenue = $invoices->sum('base_subscription_fee');
        $usageRevenue = $totalRevenue - $subscriptionRevenue;
        $grossProfit = $totalRevenue - $totalCosts;
        $profitMargin = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;
        
        return [
            'period_start' => $startDate,
            'period_end' => $endDate,
            'total_revenue' => round($totalRevenue, 2),
            'subscription_revenue' => round($subscriptionRevenue, 2),
            'usage_revenue' => round($usageRevenue, 2),
            'total_costs' => round($totalCosts, 2),
            'gross_profit' => round($grossProfit, 2),
            'profit_margin_percent' => round($profitMargin, 2),
            'invoice_count' => $invoices->count(),
            'active_customers' => $invoices->unique('org_id')->count(),
        ];
    }
}

