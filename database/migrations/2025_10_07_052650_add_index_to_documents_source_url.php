<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Add index on org_id and connector_id for faster duplicate checking
            // Note: source_url is a TEXT column, so we can't add it to the index directly
            $table->index(['org_id', 'connector_id'], 'idx_documents_org_connector');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('idx_documents_org_connector');
        });
    }
};
