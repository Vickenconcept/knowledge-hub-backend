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
        // First, clean up duplicate connectors
        echo "Cleaning up duplicate connectors...\n";
        
        // Find duplicates grouped by org_id, type, and connection_scope
        $duplicates = DB::table('connectors')
            ->select('org_id', 'type', 'connection_scope', DB::raw('COUNT(*) as count'))
            ->groupBy('org_id', 'type', 'connection_scope')
            ->having('count', '>', 1)
            ->get();
        
        foreach ($duplicates as $duplicate) {
            echo "Found duplicates for org_id: {$duplicate->org_id}, type: {$duplicate->type}, scope: {$duplicate->connection_scope}\n";
            
            // Get all connectors matching this combination
            $connectorsToCheck = DB::table('connectors')
                ->where('org_id', $duplicate->org_id)
                ->where('type', $duplicate->type)
                ->where('connection_scope', $duplicate->connection_scope)
                ->orderBy('created_at', 'asc')
                ->get();
            
            // Keep the first one, delete the rest
            $keepFirst = true;
            foreach ($connectorsToCheck as $connector) {
                if ($keepFirst) {
                    echo "  Keeping connector: {$connector->id}\n";
                    $keepFirst = false;
                    continue;
                }
                
                echo "  Deleting duplicate connector: {$connector->id}\n";
                
                // Delete related data
                DB::table('chunks')->where('document_id', function($query) use ($connector) {
                    $query->select('id')->from('documents')->where('connector_id', $connector->id);
                })->delete();
                
                DB::table('documents')->where('connector_id', $connector->id)->delete();
                DB::table('user_connector_permissions')->where('connector_id', $connector->id)->delete();
                
                // Delete the connector
                DB::table('connectors')->where('id', $connector->id)->delete();
            }
        }
        
        echo "Cleanup complete. Adding unique constraint...\n";
        
        // Now add the unique constraint
        Schema::table('connectors', function (Blueprint $table) {
            $table->unique(['org_id', 'type', 'connection_scope'], 'connectors_unique_org_type_scope');
        });
        
        echo "Migration completed successfully!\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connectors', function (Blueprint $table) {
            $table->dropUnique('connectors_unique_org_type_scope');
        });
    }
};
