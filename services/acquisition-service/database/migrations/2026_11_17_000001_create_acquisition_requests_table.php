<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->uuid('institution_id')->index();
            $table->uuid('organizational_unit_id')->nullable()->index();
            $table->uuid('requester_account_id');
            $table->string('title');
            $table->text('motif')->nullable();
            $table->string('priority')->default('normal');
            $table->timestamp('needed_by')->nullable();
            $table->string('status')->default('draft')->index();
            $table->uuid('workflow_instance_id')->nullable()->index();
            $table->string('supplier_name')->nullable();
            $table->uuid('supplier_ref')->nullable();
            $table->string('order_ref')->nullable();
            $table->decimal('contracted_total_value', 15, 2)->nullable();
            $table->decimal('total_estimated_value', 15, 2)->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_requests');
    }
};
