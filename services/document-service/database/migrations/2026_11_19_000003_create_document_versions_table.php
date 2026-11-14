<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->index();
            $table->unsignedSmallInteger('version_number');
            $table->string('original_filename', 500);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('storage_path', 1000);
            $table->string('status', 20)->default('current');
            $table->uuid('uploaded_by')->nullable();
            $table->timestampsTz();

            $table->unique(['document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
