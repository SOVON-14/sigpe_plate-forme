<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id')->index();
            $table->uuid('institution_id')->nullable()->index();
            $table->string('disposal_type', 20);
            $table->text('reason');
            $table->string('status', 30)->default('draft')->index();
            $table->decimal('estimated_value', 18, 2)->nullable();
            $table->string('currency', 3)->default('XOF');
            $table->decimal('net_book_value', 18, 2)->nullable();
            $table->boolean('is_value_determinate')->default(false);
            $table->string('required_competence_level', 20);
            $table->uuid('workflow_instance_id')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->uuid('executed_by')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disposals');
    }
};
