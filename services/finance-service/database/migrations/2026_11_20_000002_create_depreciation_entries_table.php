<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id')->index();
            $table->uuid('institution_id')->nullable()->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 18, 2);
            $table->string('method', 30);
            $table->uuid('recorded_by');
            $table->timestampsTz();

            $table->unique(['asset_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_entries');
    }
};
