<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id')->nullable();
            $table->uuid('org_id')->index();
            $table->uuid('connector_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->text('source_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('doc_type')->nullable()->index()->comment('Auto-detected: resume, report, contract, presentation, spreadsheet, code, etc.');
            $table->json('metadata')->nullable()->comment('Extracted entities, dates, keywords, categories, etc.');
            $table->text('summary')->nullable()->comment('AI-generated summary of document content');
            $table->json('tags')->nullable()->comment('Auto-extracted or user-defined tags');
            $table->string('sha256')->nullable()->index();
            $table->bigInteger('size')->nullable();
            $table->string('s3_path')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['org_id', 'connector_id'], 'idx_documents_org_connector');
            $table->index(['org_id', 'connector_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};


