<?php

namespace App\Http\Controllers;

use App\Models\CostTracking;
use App\Models\Organization;
use App\Services\CostTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CostTrackingController extends Controller
{
    /**
     * Get cost statistics for the authenticated user's organization
     */
    public function getStats(Request $request)
    {
        try {
            $user = $request->user();
            $period = $request->query('period', 'month');
            
            // Validate period
            if (!in_array($period, ['day', 'week', 'month', 'all'])) {
                $period = 'month';
            }
            
            // Get stats for user's org
            $stats = CostTrackingService::getOrgStats($user->org_id, $period);
            $dailyCosts = CostTrackingService::getDailyCosts($user->org_id, 30);
            
            return response()->json([
                'stats' => $stats,
                'daily_costs' => $dailyCosts,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching cost stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch cost statistics',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get cost breakdown by organization (admin only)
     */
    public function getOrgBreakdown(Request $request)
    {
        try {
            $user = $request->user();
            
            // Check if user is admin (you can implement your own admin logic)
            // For now, we'll return data for all orgs
            
            $period = $request->query('period', 'month');
            
            // Validate period
            if (!in_array($period, ['day', 'week', 'month', 'all'])) {
                $period = 'month';
            }
            
            // Get all organizations
            $orgs = Organization::all();
            
            $orgBreakdown = [];
            foreach ($orgs as $org) {
                $stats = CostTrackingService::getOrgStats($org->id, $period);
                
                $orgBreakdown[] = [
                    'org_id' => $org->id,
                    'org_name' => $org->name,
                    'total_cost' => $stats['total_cost'],
                    'total_tokens' => $stats['total_tokens'],
                    'total_operations' => $stats['total_operations'],
                    'embedding_cost' => $stats['by_operation']['embedding']['cost'],
                    'embedding_count' => $stats['by_operation']['embedding']['count'],
                    'chat_cost' => $stats['by_operation']['chat']['cost'],
                    'chat_count' => $stats['by_operation']['chat']['count'],
                ];
            }
            
            // Sort by total cost descending
            usort($orgBreakdown, function($a, $b) {
                return $b['total_cost'] <=> $a['total_cost'];
            });
            
            return response()->json([
                'period' => $period,
                'organizations' => $orgBreakdown,
                'total_cost' => array_sum(array_column($orgBreakdown, 'total_cost')),
                'total_operations' => array_sum(array_column($orgBreakdown, 'total_operations')),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching org breakdown', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch organization breakdown',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get detailed cost history
     */
    public function getHistory(Request $request)
    {
        try {
            $user = $request->user();
            $limit = $request->query('limit', 100);
            $offset = $request->query('offset', 0);
            
            $records = CostTracking::where('org_id', $user->org_id)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->offset($offset)
                ->get();
            
            return response()->json([
                'records' => $records,
                'total' => CostTracking::where('org_id', $user->org_id)->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching cost history', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch cost history',
            ], 500);
        }
    }
    
    /**
     * Get cost estimate for an operation
     */
    public function estimateCost(Request $request)
    {
        try {
            $validated = $request->validate([
                'operation' => 'required|in:embedding,chat',
                'model' => 'required|string',
                'tokens' => 'required|integer|min:1',
            ]);
            
            $estimatedCost = CostTrackingService::estimateCost(
                $validated['operation'],
                $validated['model'],
                $validated['tokens']
            );
            
            return response()->json([
                'operation' => $validated['operation'],
                'model' => $validated['model'],
                'tokens' => $validated['tokens'],
                'estimated_cost_usd' => $estimatedCost,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to estimate cost',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}

