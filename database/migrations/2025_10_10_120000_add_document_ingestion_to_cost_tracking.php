<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'document_ingestion' to operation_type ENUM
        DB::statement("ALTER TABLE cost_tracking MODIFY COLUMN operation_type ENUM('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull', 'document_ingestion') NOT NULL");
    }

    public function down(): void
    {
        // Remove 'document_ingestion' from ENUM
        DB::statement("ALTER TABLE cost_tracking MODIFY COLUMN operation_type ENUM('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull') NOT NULL");
    }
};

