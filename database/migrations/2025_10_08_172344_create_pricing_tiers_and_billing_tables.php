<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pricing Tiers (Free, Starter, Pro, Enterprise)
        Schema::create('pricing_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name'); // Free, Starter, Pro, Enterprise
            $table->string('display_name'); // For UI
            $table->text('description')->nullable();
            
            // Pricing
            $table->decimal('monthly_base_fee', 10, 2)->default(0); // Base subscription fee
            $table->decimal('cost_markup_multiplier', 4, 2)->default(2.0); // 2x = 200% markup
            
            // Usage Limits (NULL = unlimited)
            $table->integer('max_users')->nullable();
            $table->integer('max_documents')->nullable();
            $table->integer('max_chat_queries_per_month')->nullable();
            $table->integer('max_storage_gb')->nullable();
            $table->decimal('max_monthly_spend', 10, 2)->nullable(); // Maximum AI cost allowed
            
            // Features
            $table->boolean('custom_connectors')->default(false);
            $table->boolean('priority_support')->default(false);
            $table->boolean('api_access')->default(false);
            $table->boolean('white_label')->default(false);
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        
        // Organization Billing Settings
        Schema::create('organization_billing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->unique();
            $table->uuid('pricing_tier_id');
            
            // Current billing
            $table->enum('billing_cycle', ['monthly', 'annual'])->default('monthly');
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->enum('status', ['active', 'past_due', 'canceled', 'suspended'])->default('active');
            
            // Payment
            $table->string('payment_method')->nullable(); // stripe, paypal, invoice
            $table->string('payment_provider_customer_id')->nullable();
            
            // Thresholds
            $table->decimal('alert_threshold_percent', 5, 2)->default(80.00); // Alert at 80% of limit
            $table->boolean('auto_suspend_on_limit')->default(false);
            
            $table->timestamps();
            
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('pricing_tier_id')->references('id')->on('pricing_tiers')->onDelete('restrict');
        });
        
        // Monthly Invoices
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->string('invoice_number')->unique(); // INV-2025-10-001
            
            // Billing period
            $table->date('period_start');
            $table->date('period_end');
            
            // Costs breakdown
            $table->decimal('infrastructure_cost', 10, 2)->default(0); // What you paid (OpenAI, Pinecone)
            $table->decimal('markup_amount', 10, 2)->default(0); // Your profit margin
            $table->decimal('base_subscription_fee', 10, 2)->default(0); // Monthly tier fee
            $table->decimal('total_amount', 10, 2)->default(0); // What customer pays
            
            // Usage metrics
            $table->integer('total_chat_queries')->default(0);
            $table->integer('total_documents')->default(0);
            $table->integer('total_embeddings')->default(0);
            $table->integer('total_vector_queries')->default(0);
            
            // Status
            $table->enum('status', ['draft', 'issued', 'paid', 'overdue', 'void'])->default('draft');
            $table->date('issued_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->date('due_date')->nullable();
            
            // Payment
            $table->string('payment_method')->nullable();
            $table->string('payment_transaction_id')->nullable();
            
            $table->timestamps();
            
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });
        
        // Revenue Tracking (Your profit tracking)
        Schema::create('revenue_tracking', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('date')->index();
            
            // Customer payments (what you receive)
            $table->decimal('total_revenue', 10, 2)->default(0);
            $table->decimal('subscription_revenue', 10, 2)->default(0);
            $table->decimal('usage_revenue', 10, 2)->default(0);
            
            // Costs (what you pay)
            $table->decimal('total_costs', 10, 2)->default(0);
            $table->decimal('openai_costs', 10, 2)->default(0);
            $table->decimal('pinecone_costs', 10, 2)->default(0);
            $table->decimal('other_infrastructure_costs', 10, 2)->default(0);
            
            // Profit
            $table->decimal('gross_profit', 10, 2)->default(0); // Revenue - Costs
            $table->decimal('profit_margin_percent', 5, 2)->default(0);
            
            // Metrics
            $table->integer('active_organizations')->default(0);
            $table->integer('total_queries_processed')->default(0);
            
            $table->timestamps();
            
            $table->unique('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_tracking');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('organization_billing');
        Schema::dropIfExists('pricing_tiers');
    }
};
