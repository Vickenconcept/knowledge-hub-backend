<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_tracking', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            
            // Operation type
            $table->enum('operation_type', ['embedding', 'chat', 'summarization'])->index();
            
            // API details
            $table->string('model_used'); // e.g., text-embedding-3-small, gpt-4o-mini
            $table->string('provider')->default('openai'); // openai, anthropic, etc.
            
            // Usage metrics
            $table->integer('tokens_input')->default(0);
            $table->integer('tokens_output')->default(0);
            $table->integer('total_tokens')->default(0);
            
            // Cost calculation
            $table->decimal('cost_usd', 10, 6)->default(0); // Store in USD
            
            // Context
            $table->uuid('document_id')->nullable(); // For embeddings
            $table->uuid('conversation_id')->nullable(); // For chat
            $table->uuid('ingest_job_id')->nullable(); // For batch operations
            $table->text('query_text')->nullable(); // For chat operations
            
            // Timestamps
            $table->timestamp('created_at');
            
            // Indexes for analytics
            $table->index(['org_id', 'created_at']);
            $table->index(['org_id', 'operation_type', 'created_at']);
            
            // Foreign keys
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_tracking');
    }
};
