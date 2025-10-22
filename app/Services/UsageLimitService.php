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
     * 
     * NOTE: This tracks MONTHLY ingestion via cost_tracking to prevent quota gaming
     * (where users sync, delete, sync again to bypass limits)
     */
    public static function canAddDocument(string $orgId): array
    {
        $limits = self::getOrgLimits($orgId);
        
        if (!$limits['has_limits']) {
            return ['allowed' => true];
        }
        
        // Count CURRENT documents (for display)
        // Exclude system guide documents from quota
        $currentDocumentCount = DB::table('documents')
            ->where('org_id', $orgId)
            ->where(function($query) {
                $query->where('doc_type', '!=', 'guide')
                      ->orWhereNull('doc_type');
            })
            ->count();
        
        // Count MONTHLY document ingestion from cost_tracking (PERMANENT RECORD)
        // This counts all documents ingested this month, even if later deleted
        // This prevents quota gaming where users delete and re-sync
        $monthlyIngestion = DB::table('cost_tracking')
            ->where('org_id', $orgId)
            ->where('operation_type', 'document_ingestion')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
        
        // Fallback: If cost_tracking is empty (old data), use documents table
        // But this is less reliable as deleted documents won't be counted
        if ($monthlyIngestion === 0) {
            $monthlyIngestion = DB::table('documents')
                ->where('org_id', $orgId)
                ->where('created_at', '>=', now()->startOfMonth())
                ->where(function($query) {
                    $query->where('doc_type', '!=', 'guide')
                          ->orWhereNull('doc_type');
                })
                ->count();
        }
        
        $maxDocuments = $limits['max_documents'];
        
        // Check against MONTHLY ingestion, not current count
        if ($maxDocuments && $monthlyIngestion >= $maxDocuments) {
            return [
                'allowed' => false,
                'reason' => "You've reached your plan limit of {$maxDocuments} documents indexed this month. Deleting and re-syncing doesn't reset your quota. Please upgrade your plan or wait until next month.",
                'current_usage' => $monthlyIngestion,
                'current_active' => $currentDocumentCount,
                'limit' => $maxDocuments,
                'tier' => $limits['tier_name'],
            ];
        }
        
        return [
            'allowed' => true,
            'current_usage' => $monthlyIngestion,
            'current_active' => $currentDocumentCount,
            'limit' => $maxDocuments,
            'remaining' => $maxDocuments ? ($maxDocuments - $monthlyIngestion) : null,
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
        
        // If still no pricing tier found (fresh database), use generous defaults
        if (!$billing) {
            return [
                'has_limits' => false,
                'tier_name' => 'Unlimited',
                'max_users' => 999999,
                'max_documents' => 999999,
                'max_chat_queries_per_month' => 999999,
                'max_monthly_spend' => 999999,
            ];
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

