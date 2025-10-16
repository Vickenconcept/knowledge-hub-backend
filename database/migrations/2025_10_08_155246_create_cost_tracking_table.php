<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        
        Schema::create('cost_tracking', function (Blueprint $table) use ($driver) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            
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
            
            // Foreign keys
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
        
        // Add operation_type column with database-specific implementation
        if ($driver === 'pgsql') {
            // PostgreSQL: VARCHAR with CHECK constraint
            DB::statement("ALTER TABLE cost_tracking ADD COLUMN operation_type VARCHAR(50) NOT NULL DEFAULT 'embedding'");
            DB::statement("
                ALTER TABLE cost_tracking 
                ADD CONSTRAINT cost_tracking_operation_type_check 
                CHECK (operation_type IN ('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull', 'document_ingestion'))
            ");
            DB::statement("CREATE INDEX cost_tracking_operation_type_index ON cost_tracking(operation_type)");
            DB::statement("CREATE INDEX cost_tracking_org_operation_created_index ON cost_tracking(org_id, operation_type, created_at)");
        } else {
            // MySQL: ENUM
            DB::statement("
                ALTER TABLE cost_tracking 
                ADD COLUMN operation_type ENUM('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull', 'document_ingestion') NOT NULL DEFAULT 'embedding' AFTER user_id
            ");
            DB::statement("CREATE INDEX cost_tracking_operation_type_index ON cost_tracking(operation_type)");
            DB::statement("CREATE INDEX cost_tracking_org_operation_created_index ON cost_tracking(org_id, operation_type, created_at)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_tracking');
    }
};
