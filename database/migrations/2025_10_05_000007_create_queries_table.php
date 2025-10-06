<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->uuid('user_id')->index();
            $table->text('query_text');
            $table->integer('top_k')->default(6);
            $table->json('result_chunk_ids')->nullable();
            $table->string('model_used')->nullable();
            $table->decimal('cost_estimate', 10, 4)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queries');
    }
};


