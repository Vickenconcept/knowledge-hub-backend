<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PricingTiersSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'id' => Str::uuid(),
                'name' => 'free',
                'display_name' => 'Free',
                'description' => 'Perfect for trying out the platform',
                'monthly_base_fee' => 0.00,
                'cost_markup_multiplier' => 3.0, // 3x markup (you pay $1, customer pays $3)
                'max_users' => 2,
                'max_documents' => 50,
                'max_chat_queries_per_month' => 100,
                'max_storage_gb' => 1,
                'max_monthly_spend' => 5.00, // Max $5 in AI costs
                'custom_connectors' => false,
                'priority_support' => false,
                'api_access' => false,
                'white_label' => false,
                'is_active' => true,
            ],
            [
                'id' => Str::uuid(),
                'name' => 'starter',
                'display_name' => 'Starter',
                'description' => 'For small teams getting started',
                'monthly_base_fee' => 29.00, // $29/month base
                'cost_markup_multiplier' => 2.5, // 2.5x markup
                'max_users' => 10,
                'max_documents' => 500,
                'max_chat_queries_per_month' => 1000,
                'max_storage_gb' => 10,
                'max_monthly_spend' => 50.00,
                'custom_connectors' => false,
                'priority_support' => false,
                'api_access' => true,
                'white_label' => false,
                'is_active' => true,
            ],
            [
                'id' => Str::uuid(),
                'name' => 'pro',
                'display_name' => 'Professional',
                'description' => 'For growing teams with higher usage',
                'monthly_base_fee' => 99.00, // $99/month base
                'cost_markup_multiplier' => 2.0, // 2x markup
                'max_users' => 50,
                'max_documents' => 5000,
                'max_chat_queries_per_month' => 10000,
                'max_storage_gb' => 100,
                'max_monthly_spend' => 200.00,
                'custom_connectors' => true,
                'priority_support' => true,
                'api_access' => true,
                'white_label' => false,
                'is_active' => true,
            ],
            [
                'id' => Str::uuid(),
                'name' => 'enterprise',
                'display_name' => 'Enterprise',
                'description' => 'For large organizations with custom needs',
                'monthly_base_fee' => 499.00, // $499/month base
                'cost_markup_multiplier' => 1.5, // 1.5x markup (lower margin, higher volume)
                'max_users' => null, // Unlimited
                'max_documents' => null, // Unlimited
                'max_chat_queries_per_month' => null, // Unlimited
                'max_storage_gb' => null, // Unlimited
                'max_monthly_spend' => null, // No limit
                'custom_connectors' => true,
                'priority_support' => true,
                'api_access' => true,
                'white_label' => true,
                'is_active' => true,
            ],
        ];

        foreach ($tiers as $tier) {
            DB::table('pricing_tiers')->insert(array_merge($tier, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
        
        $this->command->info('✅ Created 4 pricing tiers: Free, Starter, Pro, Enterprise');
    }
}
