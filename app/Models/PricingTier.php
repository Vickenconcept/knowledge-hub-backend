<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PricingTier extends Model
{
    use HasUuids;

    protected $table = 'pricing_tiers';

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'monthly_base_fee',
        'cost_markup_multiplier',
        'max_users',
        'max_documents',
        'max_chat_queries_per_month',
        'max_storage_gb',
        'max_monthly_spend',
        'custom_connectors',
        'priority_support',
        'api_access',
        'white_label',
        'is_active',
    ];

    protected $casts = [
        'monthly_base_fee' => 'decimal:2',
        'cost_markup_multiplier' => 'decimal:2',
        'max_users' => 'integer',
        'max_documents' => 'integer',
        'max_chat_queries_per_month' => 'integer',
        'max_storage_gb' => 'integer',
        'max_monthly_spend' => 'decimal:2',
        'custom_connectors' => 'boolean',
        'priority_support' => 'boolean',
        'api_access' => 'boolean',
        'white_label' => 'boolean',
        'is_active' => 'boolean',
    ];
}
