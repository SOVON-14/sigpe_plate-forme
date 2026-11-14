<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id')->index();
            $table->uuid('institution_id')->nullable()->index();
            $table->string('category', 40);
            $table->string('source_type', 20)->default('manual');
            $table->uuid('source_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('XOF');
            $table->date('incurred_at');
            $table->uuid('beneficiary_account_id')->nullable();
            $table->text('description');
            $table->uuid('recorded_by');
            $table->timestampsTz();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_costs');
    }
};
