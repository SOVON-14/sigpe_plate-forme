<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_projections', function (Blueprint $table) {
            $table->uuid('asset_id')->primary();
            $table->string('patrimonial_code', 80)->nullable()->index();
            $table->string('designation', 300)->nullable();
            $table->uuid('category_id')->nullable()->index();
            $table->uuid('institution_id')->nullable()->index();
            $table->string('state', 30)->default('unknown')->index();
            $table->string('entry_mode', 40)->nullable();
            $table->boolean('value_known')->default(false);
            $table->string('last_event_type', 150)->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->timestampsTz();

            $table->index(['institution_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_projections');
    }
};
