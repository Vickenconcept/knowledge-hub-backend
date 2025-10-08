<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Document classification
            $table->string('doc_type')->nullable()->index()->after('mime_type')
                ->comment('Auto-detected: resume, report, contract, presentation, spreadsheet, code, etc.');
            
            // Rich metadata for better search and categorization
            $table->json('metadata')->nullable()->after('doc_type')
                ->comment('Extracted entities, dates, keywords, categories, etc.');
            
            // Content summary for quick preview
            $table->text('summary')->nullable()->after('metadata')
                ->comment('AI-generated summary of document content');
            
            // Keywords/tags for filtering
            $table->json('tags')->nullable()->after('summary')
                ->comment('Auto-extracted or user-defined tags');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['doc_type', 'metadata', 'summary', 'tags']);
        });
    }
};
