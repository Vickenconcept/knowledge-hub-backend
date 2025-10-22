<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - Add production-critical indexes
     */
    public function up(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            // Critical: Vector similarity searches filter by org_id
            $table->index(['org_id', 'document_id'], 'idx_chunks_org_document');
        });

        Schema::table('documents', function (Blueprint $table) {
            // Search by doc_type + org_id
            $table->index(['org_id', 'doc_type'], 'idx_documents_org_doctype');
            // Filter by fetched_at for sync operations
            $table->index(['connector_id', 'fetched_at'], 'idx_documents_connector_fetched');
        });

        Schema::table('messages', function (Blueprint $table) {
            // Query logs often search by org + date range
            $table->index(['conversation_id', 'role', 'created_at'], 'idx_messages_conv_role_date');
        });

        Schema::table('ingest_jobs', function (Blueprint $table) {
            // Job status polling
            $table->index(['connector_id', 'status', 'created_at'], 'idx_jobs_connector_status');
        });

        Schema::table('cost_tracking', function (Blueprint $table) {
            // Cost reports by operation type
            $table->index(['org_id', 'operation_type', 'created_at'], 'idx_cost_org_op_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            $table->dropIndex('idx_chunks_org_document');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('idx_documents_org_doctype');
            $table->dropIndex('idx_documents_connector_fetched');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_conv_role_date');
        });

        Schema::table('ingest_jobs', function (Blueprint $table) {
            $table->dropIndex('idx_jobs_connector_status');
        });

        Schema::table('cost_tracking', function (Blueprint $table) {
            $table->dropIndex('idx_cost_org_op_date');
        });
    }
};

