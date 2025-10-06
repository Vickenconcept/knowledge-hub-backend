<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->index();
            $table->uuid('org_id')->index();
            $table->integer('chunk_index')->default(0);
            $table->text('text');
            $table->integer('char_start')->default(0);
            $table->integer('char_end')->default(0);
            $table->integer('token_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};


