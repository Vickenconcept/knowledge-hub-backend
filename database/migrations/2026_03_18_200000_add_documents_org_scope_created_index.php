<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->indexExists('documents', 'idx_documents_org_scope_created')) {
            DB::statement(
                'CREATE INDEX idx_documents_org_scope_created ON documents (org_id, source_scope, created_at)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('documents', 'idx_documents_org_scope_created')) {
            DB::statement('DROP INDEX idx_documents_org_scope_created ON documents');
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

