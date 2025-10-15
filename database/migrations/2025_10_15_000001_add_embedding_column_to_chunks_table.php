<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        
        // Add embedding column based on database type
        if ($driver === 'pgsql') {
            // PostgreSQL: Use BYTEA for binary data
            DB::statement('ALTER TABLE chunks ADD COLUMN embedding BYTEA NULL');
        } else {
            // MySQL: Use MEDIUMBLOB
            DB::statement('ALTER TABLE chunks ADD COLUMN embedding MEDIUMBLOB NULL AFTER text');
        }
        
        // Ensure org_id index exists for faster filtering during vector search
        if ($driver === 'pgsql') {
            // PostgreSQL: Check index existence
            $indexExists = DB::select("
                SELECT 1 FROM pg_indexes 
                WHERE tablename = 'chunks' 
                AND indexname = 'chunks_org_id_index'
            ");
            
            if (empty($indexExists)) {
                DB::statement('CREATE INDEX chunks_org_id_index ON chunks(org_id)');
            }
        } else {
            // MySQL: Check index existence
            $indexExists = DB::select("SHOW INDEX FROM chunks WHERE Key_name = 'chunks_org_id_index'");
            
            if (empty($indexExists)) {
                DB::statement('CREATE INDEX chunks_org_id_index ON chunks(org_id)');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        
        // Drop the embedding column if it exists
        if ($driver === 'pgsql') {
            // PostgreSQL: Check column existence
            $columnExists = DB::select("
                SELECT 1 FROM information_schema.columns 
                WHERE table_name = 'chunks' 
                AND column_name = 'embedding'
            ");
        } else {
            // MySQL: Check column existence
            $columnExists = DB::select("SHOW COLUMNS FROM chunks LIKE 'embedding'");
        }
        
        if (!empty($columnExists)) {
            DB::statement('ALTER TABLE chunks DROP COLUMN embedding');
        }
    }
};

