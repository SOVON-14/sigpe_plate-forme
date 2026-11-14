<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_valuations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id')->index();
            $table->uuid('institution_id')->nullable()->index();
            $table->string('valuation_type', 20);
            $table->date('valuation_date');
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('XOF');
            $table->boolean('is_determinate')->default(false);
            $table->string('depreciation_method', 30)->nullable();
            $table->decimal('depreciation_rate_annual', 6, 3)->nullable();
            $table->unsignedInteger('useful_life_months')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('recorded_by');
            $table->timestampsTz();

            $table->index(['asset_id', 'valuation_type', 'valuation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_valuations');
    }
};
