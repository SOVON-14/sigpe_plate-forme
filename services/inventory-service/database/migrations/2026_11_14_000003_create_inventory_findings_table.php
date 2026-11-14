<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('institution_id')->index();
            $table->uuid('asset_id')->nullable()->index();
            $table->string('free_reference')->nullable();
            $table->string('designation_found')->nullable();
            $table->string('result', 20);
            $table->string('anomaly_type', 40)->nullable();
            $table->string('identification_method', 20);
            $table->string('location_found', 500)->nullable();
            $table->string('custodian_observed')->nullable();
            $table->text('notes')->nullable();
            $table->string('verification_status', 20)->default('pending');
            $table->uuid('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->uuid('recorded_by');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['campaign_id', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_findings');
    }
};
