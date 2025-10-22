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
        // 1. Change text columns to LONGTEXT for better capacity and utf8mb4 support
        Schema::table('chunks', function (Blueprint $table) {
            $table->longText('text')->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->longText('content')->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });

        Schema::table('conversation_summaries', function (Blueprint $table) {
            $table->longText('summary')->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });

        Schema::table('query_logs', function (Blueprint $table) {
            $table->longText('query_text')->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->longText('query_text')->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });

        Schema::table('cost_tracking', function (Blueprint $table) {
            $table->longText('query_text')->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });

        // 2. Add foreign key constraints with proper cascades
        Schema::table('messages', function (Blueprint $table) {
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('connector_id')->references('id')->on('connectors')->onDelete('set null');
        });

        Schema::table('chunks', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
        });

        Schema::table('conversation_summaries', function (Blueprint $table) {
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
        });

        Schema::table('query_logs', function (Blueprint $table) {
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });

        Schema::table('cost_tracking', function (Blueprint $table) {
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });

        Schema::table('feedback', function (Blueprint $table) {
            $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
        });

        Schema::table('ingest_jobs', function (Blueprint $table) {
            $table->foreign('connector_id')->references('id')->on('connectors')->onDelete('cascade');
        });

        // 3. Add indexes for better performance
        Schema::table('chunks', function (Blueprint $table) {
            $table->index(['document_id', 'chunk_index']);
            $table->index(['org_id', 'created_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->index(['org_id', 'connector_id', 'created_at']);
            $table->index(['org_id', 'doc_type']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['user_id', 'last_message_at']);
            $table->index(['org_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign keys first
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['connector_id']);
        });

        Schema::table('chunks', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
        });

        Schema::table('conversation_summaries', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
        });

        Schema::table('query_logs', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
        });

        Schema::table('cost_tracking', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
        });

        Schema::table('feedback', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
        });

        Schema::table('ingest_jobs', function (Blueprint $table) {
            $table->dropForeign(['connector_id']);
        });

        // Revert text columns to regular TEXT
        Schema::table('chunks', function (Blueprint $table) {
            $table->text('text')->change();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->text('content')->change();
        });

        Schema::table('conversation_summaries', function (Blueprint $table) {
            $table->text('summary')->change();
        });

        Schema::table('query_logs', function (Blueprint $table) {
            $table->text('query_text')->change();
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->text('query_text')->change();
        });

        Schema::table('cost_tracking', function (Blueprint $table) {
            $table->text('query_text')->change();
        });
    }
};
