<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL doesn't allow modifying ENUM directly, we need to use raw SQL
        DB::statement("ALTER TABLE cost_tracking MODIFY COLUMN operation_type ENUM('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull') NOT NULL");
    }

    public function down(): void
    {
        // Revert to original ENUM values
        DB::statement("ALTER TABLE cost_tracking MODIFY COLUMN operation_type ENUM('embedding', 'chat', 'summarization') NOT NULL");
    }
};
