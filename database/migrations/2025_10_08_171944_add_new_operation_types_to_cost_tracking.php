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
        
        if ($driver === 'pgsql') {
            // PostgreSQL: Change column type to VARCHAR and update CHECK constraint
            // First, alter the column type
            DB::statement("ALTER TABLE cost_tracking ALTER COLUMN operation_type TYPE VARCHAR(50)");
            
            // Drop existing constraint if it exists
            DB::statement("ALTER TABLE cost_tracking DROP CONSTRAINT IF EXISTS cost_tracking_operation_type_check");
            
            // Then add updated check constraint
            DB::statement("
                ALTER TABLE cost_tracking 
                ADD CONSTRAINT cost_tracking_operation_type_check 
                CHECK (operation_type IN ('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull', 'document_ingestion'))
            ");
        } else {
            // MySQL: Modify ENUM
            DB::statement("
                ALTER TABLE cost_tracking 
                MODIFY COLUMN operation_type ENUM('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull', 'document_ingestion') NOT NULL
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            // PostgreSQL: Drop constraint and revert
            DB::statement("ALTER TABLE cost_tracking DROP CONSTRAINT IF EXISTS cost_tracking_operation_type_check");
            
            DB::statement("
                ALTER TABLE cost_tracking 
                ADD CONSTRAINT cost_tracking_operation_type_check 
                CHECK (operation_type IN ('embedding', 'chat', 'summarization'))
            ");
        } else {
            // MySQL: Revert ENUM
            DB::statement("
                ALTER TABLE cost_tracking 
                MODIFY COLUMN operation_type ENUM('embedding', 'chat', 'summarization') NOT NULL
            ");
        }
    }
};
