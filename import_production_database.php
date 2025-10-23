<?php

/**
 * Production Database Import Helper
 * This script helps import the database structure to production
 * by creating tables in the correct order and handling foreign key constraints
 */

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "🚀 Production Database Import Helper\n";
echo "=====================================\n\n";

try {
    // Check database connection
    DB::connection()->getPdo();
    echo "✅ Database connection successful\n";
    
    // Get database name
    $database = DB::getDatabaseName();
    echo "📊 Database: {$database}\n\n";
    
    // Check if we're in production
    $isProduction = config('app.env') === 'production';
    echo "🌍 Environment: " . config('app.env') . "\n";
    
    if ($isProduction) {
        echo "⚠️  WARNING: You are in PRODUCTION environment!\n";
        echo "This will modify your production database.\n";
        echo "Are you sure you want to continue? (y/N): ";
        
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        
        if (trim(strtolower($line)) !== 'y') {
            echo "❌ Operation cancelled by user.\n";
            exit(1);
        }
    }
    
    echo "\n🔧 Running database migrations...\n";
    
    // Run the production database setup migration
    $exitCode = 0;
    $output = [];
    exec('php artisan migrate --path=database/migrations/2025_10_23_070000_production_database_setup.php 2>&1', $output, $exitCode);
    
    if ($exitCode === 0) {
        echo "✅ Database structure imported successfully!\n";
        
        // Verify tables exist
        $tables = [
            'organizations', 'users', 'connectors', 'documents', 
            'chunks', 'conversations', 'messages', 'cost_tracking'
        ];
        
        echo "\n🔍 Verifying table creation...\n";
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                echo "✅ Table '{$table}' exists\n";
            } else {
                echo "❌ Table '{$table}' missing\n";
            }
        }
        
        // Check foreign key constraints
        echo "\n🔗 Checking foreign key constraints...\n";
        $this->checkForeignKeys();
        
    } else {
        echo "❌ Migration failed:\n";
        echo implode("\n", $output) . "\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n🎉 Database import completed successfully!\n";

/**
 * Check foreign key constraints
 */
function checkForeignKeys() {
    $foreignKeys = [
        ['table' => 'users', 'column' => 'org_id', 'references' => 'organizations.id'],
        ['table' => 'connectors', 'column' => 'org_id', 'references' => 'organizations.id'],
        ['table' => 'documents', 'column' => 'org_id', 'references' => 'organizations.id'],
        ['table' => 'documents', 'column' => 'connector_id', 'references' => 'connectors.id'],
        ['table' => 'chunks', 'column' => 'document_id', 'references' => 'documents.id'],
        ['table' => 'conversations', 'column' => 'org_id', 'references' => 'organizations.id'],
        ['table' => 'conversations', 'column' => 'user_id', 'references' => 'users.id'],
        ['table' => 'messages', 'column' => 'conversation_id', 'references' => 'conversations.id'],
        ['table' => 'cost_tracking', 'column' => 'org_id', 'references' => 'organizations.id'],
        ['table' => 'cost_tracking', 'column' => 'user_id', 'references' => 'users.id'],
    ];
    
    foreach ($foreignKeys as $fk) {
        try {
            $constraints = DB::select("SHOW CREATE TABLE {$fk['table']}");
            $createTable = $constraints[0]->{'Create Table'};
            
            if (strpos($createTable, "FOREIGN KEY (`{$fk['column']}`)") !== false) {
                echo "✅ Foreign key {$fk['table']}.{$fk['column']} -> {$fk['references']}\n";
            } else {
                echo "❌ Missing foreign key {$fk['table']}.{$fk['column']} -> {$fk['references']}\n";
            }
        } catch (Exception $e) {
            echo "⚠️  Could not check foreign key {$fk['table']}.{$fk['column']}: " . $e->getMessage() . "\n";
        }
    }
}
