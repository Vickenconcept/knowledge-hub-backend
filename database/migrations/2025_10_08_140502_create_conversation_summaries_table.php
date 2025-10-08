<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->unsignedBigInteger('user_id'); // Match users.id type
            $table->uuid('org_id');
            
            // Summary content
            $table->text('summary'); // AI-generated summary of conversation segment
            $table->json('key_topics')->nullable(); // Main topics discussed
            $table->json('entities_mentioned')->nullable(); // People, companies, products mentioned
            $table->json('decisions_made')->nullable(); // Action items, conclusions
            
            // Metadata
            $table->integer('message_count')->default(0); // How many messages this summary covers
            $table->integer('turn_start')->default(0); // Starting message index
            $table->integer('turn_end')->default(0); // Ending message index
            
            // Timestamps
            $table->timestamp('period_start')->nullable(); // When this conversation segment started
            $table->timestamp('period_end')->nullable(); // When it ended
            $table->timestamps();
            
            // Indexes
            $table->index('conversation_id');
            $table->index('user_id');
            $table->index('org_id');
            $table->index('created_at');
            
            // Foreign keys
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_summaries');
    }
};
