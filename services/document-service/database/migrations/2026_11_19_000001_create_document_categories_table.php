<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60);
            $table->string('label', 200);
            $table->string('scope', 20)->default('national');
            $table->uuid('parent_id')->nullable();
            $table->uuid('institution_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['code', 'institution_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_categories');
    }
};
