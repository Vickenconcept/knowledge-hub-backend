<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    /**
     * Get current billing for authenticated user's organization
     */
    public function getCurrentBilling(Request $request)
    {
        try {
            $user = $request->user();
            $orgId = $user->org_id;
            
            // Get current month billing
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
            
            $billing = BillingService::calculateBilling($orgId, $startDate, $endDate);
            
            return response()->json($billing);
        } catch (\Exception $e) {
            Log::error('Error fetching current billing', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch billing information',
            ], 500);
        }
    }
    
    /**
     * Get billing history/invoices
     */
    public function getInvoices(Request $request)
    {
        try {
            $user = $request->user();
            $orgId = $user->org_id;
            
            $invoices = DB::table('invoices')
                ->where('org_id', $orgId)
                ->orderBy('period_end', 'desc')
                ->limit(12) // Last 12 months
                ->get();
            
            return response()->json([
                'invoices' => $invoices,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching invoices', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch invoices',
            ], 500);
        }
    }
    
    /**
     * Get specific invoice details
     */
    public function getInvoice(Request $request, $id)
    {
        try {
            $user = $request->user();
            $orgId = $user->org_id;
            
            $invoice = DB::table('invoices')
                ->where('id', $id)
                ->where('org_id', $orgId)
                ->first();
            
            if (!$invoice) {
                return response()->json([
                    'error' => 'Invoice not found',
                ], 404);
            }
            
            return response()->json($invoice);
        } catch (\Exception $e) {
            Log::error('Error fetching invoice', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch invoice',
            ], 500);
        }
    }
    
    /**
     * Get all available pricing tiers
     */
    public function getPricingTiers()
    {
        try {
            $driver = DB::connection()->getDriverName();
            
            if ($driver === 'pgsql') {
                // PostgreSQL: Use CASE WHEN for custom order
                $tiers = DB::table('pricing_tiers')
                    ->where('is_active', true)
                    ->orderByRaw("
                        CASE name 
                            WHEN 'free' THEN 1 
                            WHEN 'starter' THEN 2 
                            WHEN 'pro' THEN 3 
                            WHEN 'enterprise' THEN 4 
                            ELSE 5 
                        END
                    ")
                    ->get();
            } else {
                // MySQL: Use FIELD function
                $tiers = DB::table('pricing_tiers')
                    ->where('is_active', true)
                    ->orderByRaw("FIELD(name, 'free', 'starter', 'pro', 'enterprise')")
                    ->get();
            }
            
            return response()->json([
                'tiers' => $tiers,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching pricing tiers', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch pricing tiers',
            ], 500);
        }
    }
    
    /**
     * Get organization's current tier and billing settings
     */
    public function getOrganizationBilling(Request $request)
    {
        try {
            $user = $request->user();
            $orgId = $user->org_id;
            
            $orgBilling = DB::table('organization_billing')
                ->join('pricing_tiers', 'organization_billing.pricing_tier_id', '=', 'pricing_tiers.id')
                ->where('organization_billing.org_id', $orgId)
                ->select(
                    'organization_billing.*',
                    'pricing_tiers.name as tier_name',
                    'pricing_tiers.display_name as tier_display_name',
                    'pricing_tiers.monthly_base_fee',
                    'pricing_tiers.cost_markup_multiplier',
                    'pricing_tiers.max_users',
                    'pricing_tiers.max_documents',
                    'pricing_tiers.max_chat_queries_per_month',
                    'pricing_tiers.max_storage_gb'
                )
                ->first();
            
            // If no billing setup, return free tier info
            if (!$orgBilling) {
                $freeTier = DB::table('pricing_tiers')
                    ->where('name', 'free')
                    ->first();
                    
                return response()->json([
                    'tier_name' => 'free',
                    'tier_display_name' => 'Free',
                    'monthly_base_fee' => 0,
                    'cost_markup_multiplier' => 3.0,
                    'status' => 'active',
                    'billing_cycle' => 'monthly',
                    'max_users' => $freeTier->max_users,
                    'max_documents' => $freeTier->max_documents,
                    'max_chat_queries_per_month' => $freeTier->max_chat_queries_per_month,
                ]);
            }
            
            return response()->json($orgBilling);
        } catch (\Exception $e) {
            Log::error('Error fetching organization billing', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch organization billing',
            ], 500);
        }
    }
    
    /**
     * Get revenue summary (Admin only)
     */
    public function getRevenueSummary(Request $request)
    {
        try {
            // TODO: Add admin check
            
            $period = $request->query('period', 'month');
            
            if ($period === 'month') {
                $startDate = date('Y-m-01');
                $endDate = date('Y-m-t');
            } elseif ($period === 'year') {
                $startDate = date('Y-01-01');
                $endDate = date('Y-12-31');
            } else {
                $startDate = $request->query('start_date', date('Y-m-01'));
                $endDate = $request->query('end_date', date('Y-m-t'));
            }
            
            $summary = BillingService::getRevenueSummary($startDate, $endDate);
            
            return response()->json($summary);
        } catch (\Exception $e) {
            Log::error('Error fetching revenue summary', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch revenue summary',
            ], 500);
        }
    }
    
    /**
     * Generate invoice for current period (Admin only)
     */
    public function generateInvoice(Request $request)
    {
        try {
            $validated = $request->validate([
                'org_id' => 'required|uuid',
                'start_date' => 'required|date',
                'end_date' => 'required|date',
            ]);
            
            $invoice = BillingService::generateInvoice(
                $validated['org_id'],
                $validated['start_date'],
                $validated['end_date']
            );
            
            return response()->json([
                'message' => 'Invoice generated successfully',
                'invoice' => $invoice,
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating invoice', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to generate invoice',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

