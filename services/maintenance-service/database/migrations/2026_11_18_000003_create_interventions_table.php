<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interventions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->uuid('institution_id')->index();
            $table->uuid('request_id')->nullable()->index();
            $table->uuid('plan_id')->nullable()->index();
            $table->uuid('asset_id')->index();
            $table->string('type', 30);
            $table->text('description');
            $table->string('provider_kind', 20)->default('internal');
            $table->uuid('supplier_id')->nullable();
            $table->uuid('assigned_account_id')->nullable();
            $table->string('status', 20)->default('planned')->index();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('estimated_cost', 15, 2)->nullable();
            $table->decimal('cost_labor', 15, 2)->nullable();
            $table->decimal('cost_parts', 15, 2)->nullable();
            $table->decimal('cost_supplies', 15, 2)->nullable();
            $table->decimal('cost_transport', 15, 2)->nullable();
            $table->decimal('cost_external_services', 15, 2)->nullable();
            $table->decimal('cost_other', 15, 2)->nullable();
            $table->decimal('cost_total', 15, 2)->nullable();
            $table->text('outcome_note')->nullable();
            $table->text('motif')->nullable();
            $table->uuid('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interventions');
    }
};
