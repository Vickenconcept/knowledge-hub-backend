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
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->uuid('conversation_id'); // UUID to match conversations table
            $table->uuid('message_id'); // UUID to match messages table
            $table->unsignedBigInteger('user_id');
            $table->enum('rating', ['up', 'down'])->comment('👍 or 👎 rating');
            $table->text('comment')->nullable()->comment('Optional feedback comment');
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Indexes for performance
            $table->index(['conversation_id', 'user_id']);
            $table->index(['message_id', 'user_id']);
            $table->index('rating');
            $table->index('created_at');

            // Ensure one feedback per user per message
            $table->unique(['message_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};