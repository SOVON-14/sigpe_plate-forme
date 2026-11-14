<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id')->index();
            $table->uuid('institution_id')->index();
            $table->uuid('intervention_id')->index();
            $table->unsignedTinyInteger('quality')->nullable();
            $table->unsignedTinyInteger('timeliness')->nullable();
            $table->unsignedTinyInteger('budget')->nullable();
            $table->unsignedTinyInteger('parts_quality')->nullable();
            $table->unsignedTinyInteger('contract_compliance')->nullable();
            $table->unsignedTinyInteger('satisfaction')->nullable();
            $table->text('comment')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->unique(['supplier_id', 'intervention_id', 'institution_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_evaluations');
    }
};
