<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('connector_id')->index();
            $table->uuid('org_id')->index();
            $table->string('status')->default('queued');
            $table->json('stats')->nullable();
            $table->text('error_log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_jobs');
    }
};


