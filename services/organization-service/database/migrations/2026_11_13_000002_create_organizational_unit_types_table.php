<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizational_unit_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id')->nullable(); // NULL = type national partagé
            $table->string('code', 50);
            $table->string('label', 255);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['institution_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizational_unit_types');
    }
};
