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
        // Change the default value of source_scope from 'organization' to 'personal'
        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->enum('source_scope', ['organization', 'personal'])->default('personal')->change();
            });
        }
        
        if (Schema::hasTable('chunks')) {
            Schema::table('chunks', function (Blueprint $table) {
                $table->enum('source_scope', ['organization', 'personal'])->default('personal')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to 'organization' default
        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->enum('source_scope', ['organization', 'personal'])->default('organization')->change();
            });
        }
        
        if (Schema::hasTable('chunks')) {
            Schema::table('chunks', function (Blueprint $table) {
                $table->enum('source_scope', ['organization', 'personal'])->default('organization')->change();
            });
        }
    }
};