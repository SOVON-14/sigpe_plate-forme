<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spare_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id')->index();
            $table->string('reference', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 50);
            $table->uuid('category_id')->nullable();
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('reorder_threshold', 15, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by');
            $table->timestamps();

            $table->unique(['institution_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spare_parts');
    }
};
