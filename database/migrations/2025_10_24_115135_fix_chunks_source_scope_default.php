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
        // Remove the default value from source_scope column in chunks table
        // This allows the application code to properly set the scope
        Schema::table('chunks', function (Blueprint $table) {
            $table->enum('source_scope', ['organization', 'personal'])->nullable()->change();
        });
        
        // Also fix documents table if needed
        Schema::table('documents', function (Blueprint $table) {
            $table->enum('source_scope', ['organization', 'personal'])->nullable()->change();
        });
        
        // Update any existing NULL values to 'personal' as fallback
        DB::table('chunks')->whereNull('source_scope')->update(['source_scope' => 'personal']);
        DB::table('documents')->whereNull('source_scope')->update(['source_scope' => 'personal']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the default value
        Schema::table('chunks', function (Blueprint $table) {
            $table->enum('source_scope', ['organization', 'personal'])->default('personal')->change();
        });
        
        Schema::table('documents', function (Blueprint $table) {
            $table->enum('source_scope', ['organization', 'personal'])->default('personal')->change();
        });
    }
};