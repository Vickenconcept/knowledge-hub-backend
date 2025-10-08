<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Usage Limit Service
 * Checks and enforces subscription tier limits
 */
class UsageLimitService
{
    /**
     * Check if organization can perform a chat query
     */
    public static function canChat(string $orgId): array
    {
        $limits = self::getOrgLimits($orgId);
        
        if (!$limits['has_limits']) {
            return ['allowed' => true];
        }
        
        // Count chat queries this month
        $chatQueries = DB::table('cost_tracking')
            ->where('org_id', $orgId)
            ->where('operation_type', 'chat')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
        
        $maxQueries = $limits['max_chat_queries_per_month'];
        
        if ($maxQueries && $chatQueries >= $maxQueries) {
            return [
                'allowed' => false,
                'reason' => "You've reached your plan limit of {$maxQueries} chat queries per month. Please upgrade your plan.",
                'current_usage' => $chatQueries,
                'limit' => $maxQueries,
                'tier' => $limits['tier_name'],
            ];
        }
        
        return [
            'allowed' => true,
            'current_usage' => $chatQueries,
            'limit' => $maxQueries,
            'remaining' => $maxQueries ? ($maxQueries - $chatQueries) : null,
        ];
    }
    
    /**
     * Check if organization can upload/index documents
     */
    public static function canAddDocument(string $orgId): array
    {
        $limits = self::getOrgLimits($orgId);
        
        if (!$limits['has_limits']) {
            return ['allowed' => true];
        }
        
        // Count total documents
        $documentCount = DB::table('documents')
            ->where('org_id', $orgId)
            ->count();
        
        $maxDocuments = $limits['max_documents'];
        
        if ($maxDocuments && $documentCount >= $maxDocuments) {
            return [
                'allowed' => false,
                'reason' => "You've reached your plan limit of {$maxDocuments} documents. Please upgrade your plan or delete some documents.",
                'current_usage' => $documentCount,
                'limit' => $maxDocuments,
                'tier' => $limits['tier_name'],
            ];
        }
        
        return [
            'allowed' => true,
            'current_usage' => $documentCount,
            'limit' => $maxDocuments,
            'remaining' => $maxDocuments ? ($maxDocuments - $documentCount) : null,
        ];
    }
    
    /**
     * Check if organization can add users
     */
    public static function canAddUser(string $orgId): array
    {
        $limits = self::getOrgLimits($orgId);
        
        if (!$limits['has_limits']) {
            return ['allowed' => true];
        }
        
        // Count users
        $userCount = DB::table('users')
            ->where('org_id', $orgId)
            ->count();
        
        $maxUsers = $limits['max_users'];
        
        if ($maxUsers && $userCount >= $maxUsers) {
            return [
                'allowed' => false,
                'reason' => "You've reached your plan limit of {$maxUsers} users. Please upgrade your plan.",
                'current_usage' => $userCount,
                'limit' => $maxUsers,
                'tier' => $limits['tier_name'],
            ];
        }
        
        return [
            'allowed' => true,
            'current_usage' => $userCount,
            'limit' => $maxUsers,
            'remaining' => $maxUsers ? ($maxUsers - $userCount) : null,
        ];
    }
    
    /**
     * Check if organization exceeded monthly spend limit
     */
    public static function isWithinSpendLimit(string $orgId): array
    {
        $limits = self::getOrgLimits($orgId);
        
        if (!$limits['has_limits'] || !$limits['max_monthly_spend']) {
            return ['within_limit' => true];
        }
        
        // Calculate this month's infrastructure cost
        $monthlySpend = DB::table('cost_tracking')
            ->where('org_id', $orgId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
        
        $maxSpend = $limits['max_monthly_spend'];
        
        if ($monthlySpend >= $maxSpend) {
            return [
                'within_limit' => false,
                'reason' => "You've reached your plan's monthly spend limit of \${$maxSpend}. Operations are suspended until next month or upgrade your plan.",
                'current_spend' => $monthlySpend,
                'limit' => $maxSpend,
                'tier' => $limits['tier_name'],
            ];
        }
        
        return [
            'within_limit' => true,
            'current_spend' => $monthlySpend,
            'limit' => $maxSpend,
            'remaining' => $maxSpend - $monthlySpend,
        ];
    }
    
    /**
     * Get organization's tier limits
     */
    private static function getOrgLimits(string $orgId): array
    {
        $billing = DB::table('organization_billing')
            ->join('pricing_tiers', 'organization_billing.pricing_tier_id', '=', 'pricing_tiers.id')
            ->where('organization_billing.org_id', $orgId)
            ->select('pricing_tiers.*')
            ->first();
        
        if (!$billing) {
            // Default to Free tier limits
            $billing = DB::table('pricing_tiers')
                ->where('name', 'free')
                ->first();
        }
        
        return [
            'has_limits' => true,
            'tier_name' => $billing->display_name ?? 'Free',
            'max_users' => $billing->max_users,
            'max_documents' => $billing->max_documents,
            'max_chat_queries_per_month' => $billing->max_chat_queries_per_month,
            'max_monthly_spend' => $billing->max_monthly_spend,
        ];
    }
    
    /**
     * Get comprehensive usage status for organization
     */
    public static function getUsageStatus(string $orgId): array
    {
        $chatCheck = self::canChat($orgId);
        $docCheck = self::canAddDocument($orgId);
        $userCheck = self::canAddUser($orgId);
        $spendCheck = self::isWithinSpendLimit($orgId);
        
        $isRestricted = !$chatCheck['allowed'] || !$docCheck['allowed'] || !$userCheck['allowed'] || !$spendCheck['within_limit'];
        
        return [
            'is_restricted' => $isRestricted,
            'can_chat' => $chatCheck['allowed'],
            'can_add_documents' => $docCheck['allowed'],
            'can_add_users' => $userCheck['allowed'],
            'within_spend_limit' => $spendCheck['within_limit'],
            'checks' => [
                'chat' => $chatCheck,
                'documents' => $docCheck,
                'users' => $userCheck,
                'spend' => $spendCheck,
            ],
        ];
    }
}

