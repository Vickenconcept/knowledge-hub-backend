<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_studio_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title')->nullable();
            $table->text('query');
            $table->string('format', 40)->default('campaign_pack');
            $table->string('tone', 40)->default('direct');
            $table->string('channel', 40)->default('mixed');
            $table->integer('max_outputs')->default(12);
            $table->json('source_document_ids')->nullable();
            $table->json('source_tags')->nullable();
            $table->integer('outputs_count')->default(0);
            $table->integer('images_count')->default(0);
            $table->string('status', 40)->default('completed');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'user_id', 'created_at'], 'idx_cs_gen_org_user_created');
        });

        Schema::create('content_studio_generation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('generation_id')->index();
            $table->uuid('org_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->integer('sort_order')->default(0);
            $table->string('item_type', 50)->index();
            $table->string('title');
            $table->longText('content');
            $table->text('cta')->nullable();
            $table->longText('image_url')->nullable();
            $table->text('image_prompt')->nullable();
            $table->json('source_document_ids')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['generation_id', 'sort_order'], 'idx_cs_items_generation_order');
            $table->index(['org_id', 'user_id', 'created_at'], 'idx_cs_items_org_user_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_studio_generation_items');
        Schema::dropIfExists('content_studio_generations');
    }
};
