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
        Schema::table('documents', function (Blueprint $table) {
            // Change s3_path from VARCHAR(255) to TEXT to support longer Cloudinary URLs
            $table->text('s3_path')->nullable()->change();
            $table->text('source_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('s3_path', 255)->nullable()->change();
            $table->string('source_url', 255)->nullable()->change();
        });
    }
};
