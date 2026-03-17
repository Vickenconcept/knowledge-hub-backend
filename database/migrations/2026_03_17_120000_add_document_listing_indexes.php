<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Supports document list filtering and created_at sorting in production.
        if (!$this->indexExists('documents', 'idx_documents_org_scope_user_created')) {
            DB::statement('CREATE INDEX idx_documents_org_scope_user_created ON documents (org_id, source_scope, user_id, created_at)');
        }

        // Helps queries that primarily constrain org and sort by newest documents.
        if (!$this->indexExists('documents', 'idx_documents_org_created')) {
            DB::statement('CREATE INDEX idx_documents_org_created ON documents (org_id, created_at)');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('documents', 'idx_documents_org_scope_user_created')) {
            DB::statement('DROP INDEX idx_documents_org_scope_user_created ON documents');
        }

        if ($this->indexExists('documents', 'idx_documents_org_created')) {
            DB::statement('DROP INDEX idx_documents_org_created ON documents');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $db = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT COUNT(1) AS cnt FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$db, $table, $index]
        );

        return isset($rows[0]) && (int) $rows[0]->cnt > 0;
    }
};
