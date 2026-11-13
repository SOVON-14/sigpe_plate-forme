<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('instance_id')->index();
            $table->uuid('step_id');
            $table->unsignedInteger('step_group');
            $table->string('step_name');
            $table->string('assignee_type', 20);
            $table->string('assignee_ref');
            $table->string('mode', 10)->default('all');
            $table->unsignedInteger('sla_hours')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->string('status', 30)->default('pending');
            $table->boolean('is_delegated')->default(false);
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();

            $table->index(['instance_id', 'step_group', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_tasks');
    }
};
