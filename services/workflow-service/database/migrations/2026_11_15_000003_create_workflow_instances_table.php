<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->index();
            $table->unsignedInteger('workflow_version');
            $table->uuid('institution_id')->index();
            $table->string('operation_type', 30);
            $table->uuid('operation_ref')->index();
            $table->string('status', 30)->default('in_progress');
            $table->unsignedInteger('current_step_group')->nullable();
            $table->json('context')->nullable();
            $table->uuid('started_by');
            $table->string('decided_outcome', 30)->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'status']);
            $table->index(['operation_type', 'operation_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
    }
};
