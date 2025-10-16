<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        
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
        
        // Add embedding column based on database type
        if ($driver === 'pgsql') {
            // PostgreSQL: Use BYTEA for binary data
            DB::statement('ALTER TABLE chunks ADD COLUMN embedding BYTEA NULL');
        } else {
            // MySQL: Use MEDIUMBLOB and ensure UTF-8 encoding
            DB::statement('ALTER TABLE chunks ADD COLUMN embedding MEDIUMBLOB NULL');
            DB::statement('ALTER TABLE chunks MODIFY text LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};


