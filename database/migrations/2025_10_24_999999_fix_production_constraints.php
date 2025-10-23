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
        // 1. Fix data type inconsistencies first (only if tables exist)
        
        // Fix queries.user_id from CHAR(36) to BIGINT UNSIGNED
        if (Schema::hasTable('queries') && Schema::hasColumn('queries', 'user_id')) {
            try {
                $columnType = DB::select("SHOW COLUMNS FROM queries LIKE 'user_id'")[0]->Type ?? '';
                if (strpos($columnType, 'char') !== false) {
                    // Drop foreign key first if exists
                    try {
                        DB::statement('ALTER TABLE queries DROP FOREIGN KEY queries_user_id_foreign');
                    } catch (Exception $e) {
                        // Foreign key doesn't exist, continue
                    }
                    
                    // Change column type
                    DB::statement('ALTER TABLE queries MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL');
                }
            } catch (Exception $e) {
                // Table or column doesn't exist yet, skip
            }
        }

        // 2. Add missing foreign key constraints (only if they don't exist and tables exist)
        
        // Organizations -> Users
        if (Schema::hasTable('organizations') && Schema::hasTable('users') && !$this->foreignKeyExists('organizations', 'owner_id')) {
            try {
                Schema::table('organizations', function (Blueprint $table) {
                    $table->foreign('owner_id')->references('id')->on('users')->onDelete('set null');
                });
            } catch (Exception $e) {
                // Foreign key might already exist or tables not ready
            }
        }

        // Connectors -> Organizations
        if (!$this->foreignKeyExists('connectors', 'org_id')) {
            Schema::table('connectors', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Documents -> Organizations
        if (!$this->foreignKeyExists('documents', 'org_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Documents -> Connectors
        if (!$this->foreignKeyExists('documents', 'connector_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->foreign('connector_id')->references('id')->on('connectors')->onDelete('set null');
            });
        }

        // Chunks -> Documents
        if (!$this->foreignKeyExists('chunks', 'document_id')) {
            Schema::table('chunks', function (Blueprint $table) {
                $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
            });
        }

        // Chunks -> Organizations
        if (!$this->foreignKeyExists('chunks', 'org_id')) {
            Schema::table('chunks', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Ingest Jobs -> Connectors
        if (!$this->foreignKeyExists('ingest_jobs', 'connector_id')) {
            Schema::table('ingest_jobs', function (Blueprint $table) {
                $table->foreign('connector_id')->references('id')->on('connectors')->onDelete('cascade');
            });
        }

        // Ingest Jobs -> Organizations
        if (!$this->foreignKeyExists('ingest_jobs', 'org_id')) {
            Schema::table('ingest_jobs', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Queries -> Organizations
        if (!$this->foreignKeyExists('queries', 'org_id')) {
            Schema::table('queries', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Queries -> Users
        if (!$this->foreignKeyExists('queries', 'user_id')) {
            Schema::table('queries', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // Query Logs -> Organizations
        if (!$this->foreignKeyExists('query_logs', 'org_id')) {
            Schema::table('query_logs', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Query Logs -> Users
        if (!$this->foreignKeyExists('query_logs', 'user_id')) {
            Schema::table('query_logs', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // Conversations -> Organizations
        if (!$this->foreignKeyExists('conversations', 'org_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Conversations -> Users
        if (!$this->foreignKeyExists('conversations', 'user_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // Messages -> Conversations
        if (!$this->foreignKeyExists('messages', 'conversation_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            });
        }

        // Conversation Summaries -> Conversations
        if (!$this->foreignKeyExists('conversation_summaries', 'conversation_id')) {
            Schema::table('conversation_summaries', function (Blueprint $table) {
                $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            });
        }

        // Conversation Summaries -> Users
        if (!$this->foreignKeyExists('conversation_summaries', 'user_id')) {
            Schema::table('conversation_summaries', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // Cost Tracking -> Organizations
        if (!$this->foreignKeyExists('cost_tracking', 'org_id')) {
            Schema::table('cost_tracking', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Cost Tracking -> Users
        if (!$this->foreignKeyExists('cost_tracking', 'user_id')) {
            Schema::table('cost_tracking', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            });
        }

        // Feedback -> Conversations
        if (!$this->foreignKeyExists('feedback', 'conversation_id')) {
            Schema::table('feedback', function (Blueprint $table) {
                $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            });
        }

        // Feedback -> Messages
        if (!$this->foreignKeyExists('feedback', 'message_id')) {
            Schema::table('feedback', function (Blueprint $table) {
                $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
            });
        }

        // Feedback -> Users
        if (!$this->foreignKeyExists('feedback', 'user_id')) {
            Schema::table('feedback', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // Organization Billing -> Organizations
        if (!$this->foreignKeyExists('organization_billing', 'org_id')) {
            Schema::table('organization_billing', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Organization Billing -> Pricing Tiers
        if (!$this->foreignKeyExists('organization_billing', 'pricing_tier_id')) {
            Schema::table('organization_billing', function (Blueprint $table) {
                $table->foreign('pricing_tier_id')->references('id')->on('pricing_tiers')->onDelete('restrict');
            });
        }

        // Invoices -> Organizations
        if (!$this->foreignKeyExists('invoices', 'org_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Sessions -> Users
        if (!$this->foreignKeyExists('sessions', 'user_id')) {
            Schema::table('sessions', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    /**
     * Check if a foreign key constraint exists
     */
    private function foreignKeyExists(string $table, string $column): bool
    {
        try {
            $constraints = DB::select("SHOW CREATE TABLE {$table}");
            if (empty($constraints)) {
                return false;
            }
            
            $createTable = $constraints[0]->{'Create Table'};
            return strpos($createTable, "FOREIGN KEY (`{$column}`)") !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop all foreign keys in reverse order
        $tables = [
            'sessions' => ['user_id'],
            'invoices' => ['org_id'],
            'organization_billing' => ['org_id', 'pricing_tier_id'],
            'feedback' => ['conversation_id', 'message_id', 'user_id'],
            'cost_tracking' => ['org_id', 'user_id'],
            'conversation_summaries' => ['conversation_id', 'user_id'],
            'messages' => ['conversation_id'],
            'conversations' => ['org_id', 'user_id'],
            'query_logs' => ['org_id', 'user_id'],
            'queries' => ['org_id', 'user_id'],
            'ingest_jobs' => ['connector_id', 'org_id'],
            'chunks' => ['document_id', 'org_id'],
            'documents' => ['org_id', 'connector_id'],
            'connectors' => ['org_id'],
            'organizations' => ['owner_id'],
        ];

        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                try {
                    $constraintName = $this->getForeignKeyName($table, $column);
                    if ($constraintName) {
                        DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$constraintName}");
                    }
                } catch (Exception $e) {
                    // Foreign key doesn't exist, continue
                }
            }
        }
    }

    /**
     * Get the foreign key constraint name
     */
    private function getForeignKeyName(string $table, string $column): ?string
    {
        try {
            $constraints = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = '{$table}' 
                AND COLUMN_NAME = '{$column}' 
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            return $constraints[0]->CONSTRAINT_NAME ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
};
