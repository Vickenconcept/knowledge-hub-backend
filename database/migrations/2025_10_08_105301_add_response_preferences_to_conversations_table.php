<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Response style configuration
            $table->string('response_style')->default('comprehensive')->after('title')
                ->comment('Response format: comprehensive, structured_profile, summary_report, qa_friendly, bullet_brief, etc.');
            
            // Additional preferences
            $table->json('preferences')->nullable()->after('response_style')
                ->comment('Detail level, tone, include_sources, max_length, etc.');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['response_style', 'preferences']);
        });
    }
};
