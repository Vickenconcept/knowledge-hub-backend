<?php

namespace App\Http\Controllers;

use App\Models\PricingTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PricingTierController extends Controller
{
    /**
     * Get all pricing tiers (including inactive for admin)
     */
    public function index()
    {
        try {
            // Database-agnostic custom ordering
            $driver = DB::connection()->getDriverName();
            
            if ($driver === 'pgsql') {
                // PostgreSQL: Use CASE WHEN for custom order
                $tiers = PricingTier::orderByRaw("
                    CASE name 
                        WHEN 'free' THEN 1 
                        WHEN 'starter' THEN 2 
                        WHEN 'pro' THEN 3 
                        WHEN 'enterprise' THEN 4 
                        ELSE 5 
                    END
                ")->get();
            } else {
                // MySQL: Use FIELD function
                $tiers = PricingTier::orderByRaw("FIELD(name, 'free', 'starter', 'pro', 'enterprise')")
                    ->get();
            }
            
            // Add subscriber count to each tier
            $tiersWithStats = $tiers->map(function($tier) {
                $subscriberCount = DB::table('organization_billing')
                    ->where('pricing_tier_id', $tier->id)
                    ->where('status', 'active')
                    ->count();
                
                return array_merge($tier->toArray(), [
                    'active_subscribers' => $subscriberCount,
                ]);
            });
            
            return response()->json([
                'tiers' => $tiersWithStats,
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
     * Get single pricing tier
     */
    public function show($id)
    {
        try {
            $tier = PricingTier::findOrFail($id);
            
            $subscriberCount = DB::table('organization_billing')
                ->where('pricing_tier_id', $tier->id)
                ->where('status', 'active')
                ->count();
            
            return response()->json(array_merge($tier->toArray(), [
                'active_subscribers' => $subscriberCount,
            ]));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Pricing tier not found',
            ], 404);
        }
    }
    
    /**
     * Create new pricing tier
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|unique:pricing_tiers,name',
                'display_name' => 'required|string',
                'description' => 'nullable|string',
                'monthly_base_fee' => 'required|numeric|min:0',
                'cost_markup_multiplier' => 'required|numeric|min:1|max:10',
                'max_users' => 'nullable|integer|min:1',
                'max_documents' => 'nullable|integer|min:1',
                'max_chat_queries_per_month' => 'nullable|integer|min:1',
                'max_storage_gb' => 'nullable|integer|min:1',
                'max_monthly_spend' => 'nullable|numeric|min:0',
                'custom_connectors' => 'boolean',
                'priority_support' => 'boolean',
                'api_access' => 'boolean',
                'white_label' => 'boolean',
                'is_active' => 'boolean',
            ]);
            
            $tier = PricingTier::create($validated);
            
            Log::info('Pricing tier created', [
                'tier_id' => $tier->id,
                'name' => $tier->name,
            ]);
            
            return response()->json([
                'message' => 'Pricing tier created successfully',
                'tier' => $tier,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating pricing tier', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to create pricing tier',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    
    /**
     * Update pricing tier
     */
    public function update(Request $request, $id)
    {
        try {
            $tier = PricingTier::findOrFail($id);
            
            $validated = $request->validate([
                'name' => 'sometimes|string|unique:pricing_tiers,name,' . $id,
                'display_name' => 'sometimes|string',
                'description' => 'nullable|string',
                'monthly_base_fee' => 'sometimes|numeric|min:0',
                'cost_markup_multiplier' => 'sometimes|numeric|min:1|max:10',
                'max_users' => 'nullable|integer|min:1',
                'max_documents' => 'nullable|integer|min:1',
                'max_chat_queries_per_month' => 'nullable|integer|min:1',
                'max_storage_gb' => 'nullable|integer|min:1',
                'max_monthly_spend' => 'nullable|numeric|min:0',
                'custom_connectors' => 'sometimes|boolean',
                'priority_support' => 'sometimes|boolean',
                'api_access' => 'sometimes|boolean',
                'white_label' => 'sometimes|boolean',
                'is_active' => 'sometimes|boolean',
            ]);
            
            $tier->update($validated);
            
            Log::info('Pricing tier updated', [
                'tier_id' => $tier->id,
                'name' => $tier->name,
                'changes' => array_keys($validated),
            ]);
            
            return response()->json([
                'message' => 'Pricing tier updated successfully',
                'tier' => $tier->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating pricing tier', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to update pricing tier',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    
    /**
     * Toggle tier active status
     */
    public function toggleActive($id)
    {
        try {
            $tier = PricingTier::findOrFail($id);
            
            $tier->is_active = !$tier->is_active;
            $tier->save();
            
            Log::info('Pricing tier status toggled', [
                'tier_id' => $tier->id,
                'name' => $tier->name,
                'is_active' => $tier->is_active,
            ]);
            
            return response()->json([
                'message' => $tier->is_active ? 'Tier activated' : 'Tier deactivated',
                'tier' => $tier,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to toggle tier status',
            ], 400);
        }
    }
    
    /**
     * Delete pricing tier
     */
    public function destroy($id)
    {
        try {
            $tier = PricingTier::findOrFail($id);
            
            // Check if any orgs are using this tier
            $subscriberCount = DB::table('organization_billing')
                ->where('pricing_tier_id', $tier->id)
                ->count();
            
            if ($subscriberCount > 0) {
                return response()->json([
                    'error' => "Cannot delete tier with {$subscriberCount} active subscribers",
                ], 400);
            }
            
            $tierName = $tier->name;
            $tier->delete();
            
            Log::info('Pricing tier deleted', [
                'tier_id' => $id,
                'name' => $tierName,
            ]);
            
            return response()->json([
                'message' => 'Pricing tier deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete pricing tier',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}

