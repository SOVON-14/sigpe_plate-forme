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
            $table->string('reference', 30)->unique();
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->uuid('category_id')->index();
            $table->uuid('institution_id')->nullable()->index();
            $table->uuid('unit_id')->nullable();
            $table->uuid('asset_id')->nullable()->index();
            $table->string('confidentiality', 30)->default('interne');
            $table->string('lifecycle_stage', 40)->default('active')->index();
            $table->unsignedSmallInteger('current_version')->default(0);
            $table->date('retention_until')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->uuid('validated_by')->nullable();
            $table->string('document_date')->nullable();
            $table->string('issuer', 300)->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestampsTz();

            $table->index(['institution_id', 'lifecycle_stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
