<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Speeds up: documents filtered by org + connector and ordered by created_at.
        Schema::table('documents', function (Blueprint $table) {
            $table->index(['org_id', 'connector_id', 'created_at'], 'idx_documents_org_connector_created');
        });

        // Speeds up: selecting manual_upload connectors within org.
        Schema::table('connectors', function (Blueprint $table) {
            $table->index(['org_id', 'type', 'id'], 'idx_connectors_org_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('idx_documents_org_connector_created');
        });

        Schema::table('connectors', function (Blueprint $table) {
            $table->dropIndex('idx_connectors_org_type_id');
        });
    }
};

