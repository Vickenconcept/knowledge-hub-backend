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

        // 2. Add foreign key constraints with proper cascades (only if they don't exist)
        
        // Messages table - check if foreign key already exists
        if (!$this->foreignKeyExists('messages', 'conversation_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            });
        }

        // Documents table
        if (!$this->foreignKeyExists('documents', 'connector_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->foreign('connector_id')->references('id')->on('connectors')->onDelete('set null');
            });
        }

        // Chunks table
        if (!$this->foreignKeyExists('chunks', 'document_id')) {
            Schema::table('chunks', function (Blueprint $table) {
                $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
            });
        }

        // Conversation summaries table
        if (!$this->foreignKeyExists('conversation_summaries', 'conversation_id')) {
            Schema::table('conversation_summaries', function (Blueprint $table) {
                $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            });
        }

        // Query logs table
        if (!$this->foreignKeyExists('query_logs', 'org_id')) {
            Schema::table('query_logs', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }
        
        if (!$this->foreignKeyExists('query_logs', 'user_id')) {
            Schema::table('query_logs', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // Queries table
        if (!$this->foreignKeyExists('queries', 'org_id')) {
            Schema::table('queries', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Cost tracking table
        if (!$this->foreignKeyExists('cost_tracking', 'org_id')) {
            Schema::table('cost_tracking', function (Blueprint $table) {
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Feedback table
        if (!$this->foreignKeyExists('feedback', 'message_id')) {
            Schema::table('feedback', function (Blueprint $table) {
                $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
            });
        }

        // Ingest jobs table
        if (!$this->foreignKeyExists('ingest_jobs', 'connector_id')) {
            Schema::table('ingest_jobs', function (Blueprint $table) {
                $table->foreign('connector_id')->references('id')->on('connectors')->onDelete('cascade');
            });
        }

        // 3. Add indexes for better performance (only if they don't exist)
        // Most indexes already exist from previous migrations, so we'll skip them
        // This section is kept for future reference but indexes are already in place
    }

    /**
     * Check if a foreign key constraint exists
     */
    private function foreignKeyExists(string $table, string $column): bool
    {
        $constraints = DB::select("SHOW CREATE TABLE {$table}");
        $createTable = $constraints[0]->{'Create Table'};
        
        return strpos($createTable, "FOREIGN KEY (`{$column}`)") !== false;
    }

    /**
     * Check if an index exists
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM {$table}");
        
        foreach ($indexes as $index) {
            if ($index->Key_name === $indexName) {
                return true;
            }
        }
        
        return false;
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
