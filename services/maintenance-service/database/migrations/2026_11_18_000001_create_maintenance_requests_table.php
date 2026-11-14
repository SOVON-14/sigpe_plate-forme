<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->uuid('institution_id')->index();
            $table->uuid('asset_id')->index();
            $table->uuid('requested_by_unit_id')->nullable();
            $table->string('type', 30);
            $table->text('description');
            $table->string('urgency', 20)->default('normal');
            $table->text('symptoms')->nullable();
            $table->decimal('estimated_cost', 15, 2)->nullable();
            $table->boolean('is_urgent_track')->default(false);
            $table->text('urgent_justification')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->uuid('workflow_instance_id')->nullable()->index();
            $table->uuid('requester_account_id');
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->uuid('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_requests');
    }
};
