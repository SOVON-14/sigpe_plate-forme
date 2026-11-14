<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_subcategories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('category_id');
            $table->uuid('institution_id')->nullable(); // NULL = sous-catégorie nationale
            $table->string('code', 50);
            $table->string('label', 255);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('category_id')->references('id')->on('asset_categories')->cascadeOnDelete();
            $table->index(['category_id', 'institution_id', 'code'], 'asset_subcategories_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_subcategories');
    }
};
