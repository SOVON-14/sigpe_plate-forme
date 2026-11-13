<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizational_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('parent_id')->nullable();
            $table->uuid('unit_type_id');
            $table->string('code', 50);
            $table->string('name', 255);
            $table->string('status', 20)->default('active');
            $table->uuid('head_position_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')->references('id')->on('organizational_units')->nullOnDelete();
            $table->unique(['institution_id', 'code']);
            $table->index(['institution_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizational_units');
    }
};
