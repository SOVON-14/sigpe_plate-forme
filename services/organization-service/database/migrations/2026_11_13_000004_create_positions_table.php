<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organizational_unit_id');
            $table->string('code', 50);
            $table->string('title', 255);
            $table->uuid('functional_account_id')->nullable(); // référence Identity Service (pas de FK inter-services)
            $table->string('status', 20)->default('active');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organizational_unit_id')->references('id')->on('organizational_units')->cascadeOnDelete();
            $table->unique(['organizational_unit_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
