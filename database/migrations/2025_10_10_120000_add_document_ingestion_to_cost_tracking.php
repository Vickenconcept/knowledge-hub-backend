fresh <?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            // PostgreSQL: Update CHECK constraint
            DB::statement("ALTER TABLE cost_tracking DROP CONSTRAINT IF EXISTS cost_tracking_operation_type_check");
            
            DB::statement("
                ALTER TABLE cost_tracking 
                ADD CONSTRAINT cost_tracking_operation_type_check 
                CHECK (operation_type IN ('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull', 'document_ingestion'))
            ");
        } else {
            // MySQL: Add 'document_ingestion' to operation_type ENUM
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
            // PostgreSQL: Remove 'document_ingestion' from constraint
            DB::statement("ALTER TABLE cost_tracking DROP CONSTRAINT IF EXISTS cost_tracking_operation_type_check");
            
            DB::statement("
                ALTER TABLE cost_tracking 
                ADD CONSTRAINT cost_tracking_operation_type_check 
                CHECK (operation_type IN ('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull'))
            ");
        } else {
            // MySQL: Remove 'document_ingestion' from ENUM
            DB::statement("
                ALTER TABLE cost_tracking 
                MODIFY COLUMN operation_type ENUM('embedding', 'chat', 'summarization', 'vector_query', 'vector_upsert', 'file_pull') NOT NULL
            ");
        }
    }
};

