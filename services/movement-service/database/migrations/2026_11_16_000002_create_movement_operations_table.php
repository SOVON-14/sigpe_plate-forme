<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movement_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 40);
            $table->string('status', 40)->default('draft');
            $table->uuid('institution_id');
            $table->uuid('destination_institution_id')->nullable();
            $table->uuid('origin_unit_id')->nullable();
            $table->uuid('destination_unit_id')->nullable();
            $table->uuid('operation_ref')->nullable();
            $table->uuid('workflow_instance_id')->nullable();
            $table->text('motif')->nullable();
            $table->timestamp('loan_start_at')->nullable();
            $table->timestamp('loan_end_at')->nullable();
            $table->uuid('custodian_account_id')->nullable();
            $table->string('convention_ref', 255)->nullable();
            $table->text('conditions')->nullable();
            $table->uuid('departure_confirmed_by')->nullable();
            $table->timestamp('departure_confirmed_at')->nullable();
            $table->uuid('reception_confirmed_by')->nullable();
            $table->timestamp('reception_confirmed_at')->nullable();
            $table->timestamp('asset_confirmed_at')->nullable();
            $table->json('asset_confirmations')->nullable();
            $table->string('incident_type', 30)->nullable();
            $table->text('incident_description')->nullable();
            $table->json('incident_evidence_refs')->nullable();
            $table->string('holder_type', 30)->nullable();
            $table->uuid('holder_ref')->nullable();
            $table->string('custody_role', 40)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'status']);
            $table->index(['type', 'status']);
            $table->index('workflow_instance_id');
            $table->index('operation_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_operations');
    }
};
