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
            $table->uuid('org_id')->index();
            $table->uuid('connector_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->text('source_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('sha256')->nullable()->index();
            $table->bigInteger('size')->nullable();
            $table->string('s3_path')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};


