<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervention_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id')->index();
            $table->uuid('asset_id')->index();
            $table->string('type', 30);
            $table->string('description', 500);
            $table->json('trigger');
            $table->string('status', 20)->default('active')->index();
            $table->uuid('assigned_supplier_id')->nullable();
            $table->decimal('estimated_cost', 15, 2)->nullable();
            $table->timestamp('last_executed_at')->nullable();
            $table->date('next_due_at')->nullable();
            $table->uuid('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervention_plans');
    }
};
