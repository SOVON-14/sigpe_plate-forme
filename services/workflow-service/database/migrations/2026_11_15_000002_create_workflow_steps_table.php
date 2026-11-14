<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->index();
            $table->unsignedInteger('step_group');
            $table->unsignedInteger('sequence')->default(1);
            $table->string('name');
            $table->string('assignee_type', 20);
            $table->string('assignee_ref');
            $table->string('mode', 10)->default('all');
            $table->unsignedInteger('sla_hours')->nullable();
            $table->boolean('is_national_obligatory')->default(false);
            $table->timestamps();

            $table->index(['workflow_id', 'step_group', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};
