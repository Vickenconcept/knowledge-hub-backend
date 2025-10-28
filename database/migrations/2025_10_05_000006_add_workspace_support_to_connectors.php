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
        // Add workspace support to existing connectors table
        Schema::table('connectors', function (Blueprint $table) {
            $table->string('workspace_name')->nullable()->after('label');
            $table->string('workspace_id')->nullable()->after('workspace_name');
            $table->enum('connection_scope', ['organization', 'personal'])->default('organization')->after('workspace_id');
            $table->boolean('is_primary')->default(false)->after('connection_scope');
            $table->json('workspace_metadata')->nullable()->after('is_primary');
        });

        // Add user-specific connector permissions
        Schema::create('user_connector_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->uuid('connector_id');
            $table->enum('permission_level', ['read', 'write', 'admin'])->default('read');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('connector_id')->references('id')->on('connectors')->onDelete('cascade');
            $table->unique(['user_id', 'connector_id']);
        });

        // Add workspace context to documents (if table exists)
        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->string('workspace_name')->nullable()->after('connector_id');
                $table->enum('source_scope', ['organization', 'personal'])->default('organization')->after('workspace_name');
            });
        }

        // Add workspace context to chunks (if table exists)
        if (Schema::hasTable('chunks')) {
            Schema::table('chunks', function (Blueprint $table) {
                $table->string('workspace_name')->nullable()->after('org_id');
                $table->enum('source_scope', ['organization', 'personal'])->default('organization')->after('workspace_name');
            });
        }

        // Add workspace context to conversations (if table exists)
        if (Schema::hasTable('conversations')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->json('workspace_context')->nullable()->after('org_id');
                $table->enum('search_scope', ['organization', 'personal', 'both'])->default('both')->after('workspace_context');
            });
        }

        // Add workspace context to messages (if table exists)
        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->json('source_workspaces')->nullable()->after('sources');
                $table->json('workspace_tags')->nullable()->after('source_workspaces');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop workspace columns from messages table
        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn(['source_workspaces', 'workspace_tags']);
            });
        }

        // Drop workspace columns from conversations table
        if (Schema::hasTable('conversations')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropColumn(['workspace_context', 'search_scope']);
            });
        }

        // Drop workspace columns from chunks table
        if (Schema::hasTable('chunks')) {
            Schema::table('chunks', function (Blueprint $table) {
                $table->dropColumn(['workspace_name', 'source_scope']);
            });
        }

        // Drop workspace columns from documents table
        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropColumn(['workspace_name', 'source_scope']);
            });
        }

        // Drop user connector permissions table
        Schema::dropIfExists('user_connector_permissions');

        // Drop workspace columns from connectors table
        Schema::table('connectors', function (Blueprint $table) {
            $table->dropColumn([
                'workspace_name',
                'workspace_id', 
                'connection_scope',
                'is_primary',
                'workspace_metadata'
            ]);
        });
    }
};
