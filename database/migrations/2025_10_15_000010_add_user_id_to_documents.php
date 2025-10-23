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
        // Add user_id to documents table to track which user uploaded each document
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('connector_id');
            $table->index(['user_id', 'org_id'], 'idx_documents_user_org');
            $table->index(['user_id', 'connector_id'], 'idx_documents_user_connector');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('idx_documents_user_org');
            $table->dropIndex('idx_documents_user_connector');
            $table->dropColumn('user_id');
        });
    }
};
