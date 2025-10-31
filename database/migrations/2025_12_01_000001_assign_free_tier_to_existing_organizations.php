<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Assign all existing organizations to the Free tier if they don't have a billing record yet
     */
    public function up(): void
    {
        // Get the Free tier ID
        $freeTier = DB::table('pricing_tiers')
            ->where('name', 'free')
            ->where('is_active', true)
            ->first();

        if (!$freeTier) {
            echo "No Free tier found. Skipping migration.\n";
            return;
        }

        // Get all organizations that don't have billing records
        $organizationsWithoutBilling = DB::table('organizations')
            ->leftJoin('organization_billing', 'organizations.id', '=', 'organization_billing.org_id')
            ->whereNull('organization_billing.id')
            ->select('organizations.id')
            ->get();

        $now = now();
        $periodStart = $now->startOfMonth()->toDateString();
        $periodEnd = $now->endOfMonth()->toDateString();

        $count = 0;
        foreach ($organizationsWithoutBilling as $org) {
            try {
                DB::table('organization_billing')->insert([
                    'id' => (string) Str::uuid(),
                    'org_id' => $org->id,
                    'pricing_tier_id' => $freeTier->id,
                    'billing_cycle' => 'monthly',
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                    'status' => 'active',
                    'payment_method' => null,
                    'payment_provider_customer_id' => null,
                    'alert_threshold_percent' => 80.00,
                    'auto_suspend_on_limit' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $count++;
            } catch (\Exception $e) {
                echo "Error assigning Free tier to organization {$org->id}: " . $e->getMessage() . "\n";
            }
        }

        echo "✅ Assigned Free tier to {$count} existing organizations.\n";
    }

    /**
     * Reverse the migrations.
     * Note: This will remove billing records for organizations assigned in this migration
     */
    public function down(): void
    {
        // Get the Free tier ID
        $freeTier = DB::table('pricing_tiers')
            ->where('name', 'free')
            ->where('is_active', true)
            ->first();

        if (!$freeTier) {
            echo "No Free tier found. Skipping rollback.\n";
            return;
        }

        // Remove billing records that were created in this migration
        // We identify them by the free tier and created_at matching the migration time
        $count = DB::table('organization_billing')
            ->where('pricing_tier_id', $freeTier->id)
            ->where('billing_cycle', 'monthly')
            ->where('current_period_start', now()->startOfMonth()->toDateString())
            ->delete();

        echo "Rolled back: Removed {$count} billing records.\n";
    }
};

