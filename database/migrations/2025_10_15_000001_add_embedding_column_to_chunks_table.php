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
        // Use raw SQL to add MEDIUMBLOB column (Laravel doesn't have mediumBlob() method)
        DB::statement('ALTER TABLE chunks ADD COLUMN embedding MEDIUMBLOB NULL AFTER text');
        
        // Ensure org_id index exists for faster filtering during vector search
        // Check if index already exists before adding
        $indexExists = DB::select("SHOW INDEX FROM chunks WHERE Key_name = 'chunks_org_id_index'");
        
        if (empty($indexExists)) {
            DB::statement('CREATE INDEX chunks_org_id_index ON chunks(org_id)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the embedding column if it exists
        $columnExists = DB::select("SHOW COLUMNS FROM chunks LIKE 'embedding'");
        
        if (!empty($columnExists)) {
            DB::statement('ALTER TABLE chunks DROP COLUMN embedding');
        }
    }
};

