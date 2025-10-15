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
        // Only run for MySQL - PostgreSQL already handles UTF-8 properly
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'mysql') {
            // Change chunks.text column to use utf8mb4 encoding for special characters
            DB::statement('ALTER TABLE chunks MODIFY text LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        // PostgreSQL: No action needed - already UTF-8 by default
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only run for MySQL
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'mysql') {
            // Revert back to default encoding
            DB::statement('ALTER TABLE chunks MODIFY text TEXT');
        }
        // PostgreSQL: No action needed
    }
};
