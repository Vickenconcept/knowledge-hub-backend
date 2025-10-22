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
        
        // Only run for MySQL/MariaDB
        if (!in_array($driver, ['mysql', 'mariadb'])) {
            return;
        }

        // Get database name
        $database = DB::getDatabaseName();

        // Convert database to utf8mb4
        DB::statement("ALTER DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // List of tables and their text columns that need utf8mb4
        $tables = [
            'chunks' => ['text'],
            'documents' => ['title', 'source_url', 'mime_type', 's3_path', 'metadata'],
            'connectors' => ['type', 'label', 'credentials', 'config', 'status', 'error_log'],
            'organizations' => ['name', 'slug'],
            'users' => ['name', 'email', 'password', 'role'],
            'conversations' => ['title'],
            'messages' => ['query', 'answer', 'sources'],
            'session_memory' => ['summary'],
            'feedback' => ['comment'],
            'ingest_jobs' => ['status', 'stats', 'error_log'],
            'cost_tracking' => ['operation_type', 'metadata'],
            'password_resets' => ['email', 'token'],
        ];

        foreach ($tables as $table => $columns) {
            // Check if table exists
            if (!Schema::hasTable($table)) {
                continue;
            }

            // Convert table to utf8mb4
            try {
                DB::statement("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                echo "✅ Converted table: {$table}\n";
            } catch (\Exception $e) {
                echo "⚠️ Could not convert table {$table}: " . $e->getMessage() . "\n";
            }

            // Also explicitly set specific columns (especially TEXT and LONGTEXT)
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    continue;
                }

                try {
                    $columnType = DB::getSchemaBuilder()->getColumnType($table, $column);
                    
                    // Map column types to their SQL equivalents
                    $typeMap = [
                        'string' => 'VARCHAR(255)',
                        'text' => 'TEXT',
                        'mediumtext' => 'MEDIUMTEXT',
                        'longtext' => 'LONGTEXT',
                        'json' => 'JSON',
                    ];

                    if (isset($typeMap[$columnType])) {
                        $sqlType = $typeMap[$columnType];
                        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$sqlType} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    }
                } catch (\Exception $e) {
                    // Skip if column doesn't exist or can't be modified
                    echo "⚠️ Could not modify column {$table}.{$column}: " . $e->getMessage() . "\n";
                }
            }
        }

        // Special case for chunks.text - ensure it's LONGTEXT for large content
        if (Schema::hasTable('chunks') && Schema::hasColumn('chunks', 'text')) {
            try {
                DB::statement("ALTER TABLE `chunks` MODIFY `text` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                echo "✅ Set chunks.text to LONGTEXT utf8mb4\n";
            } catch (\Exception $e) {
                echo "⚠️ Could not modify chunks.text: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is non-reversible as it's a data compatibility fix
        // Reverting to utf8 could cause data loss
    }
};
