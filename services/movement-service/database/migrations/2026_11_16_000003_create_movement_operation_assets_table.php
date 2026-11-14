<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movement_operation_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('operation_id');
            $table->uuid('asset_id');
            $table->string('patrimonial_code', 100)->nullable();
            $table->timestamps();

            $table->unique(['operation_id', 'asset_id']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_operation_assets');
    }
};
