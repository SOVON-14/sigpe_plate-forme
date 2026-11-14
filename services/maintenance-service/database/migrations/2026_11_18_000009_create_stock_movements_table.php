<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id')->index();
            $table->uuid('spare_part_id')->index();
            $table->uuid('warehouse_id')->index();
            $table->string('type', 20);
            $table->decimal('quantity', 15, 3);
            $table->uuid('target_warehouse_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('reference', 255)->nullable();
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->uuid('actor');
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
