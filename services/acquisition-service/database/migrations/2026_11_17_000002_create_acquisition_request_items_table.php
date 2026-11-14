<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_request_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('acquisition_request_id')->constrained('acquisition_requests')->cascadeOnDelete();
            $table->string('description');
            $table->text('specifications')->nullable();
            $table->decimal('quantity_ordered', 12, 3);
            $table->decimal('quantity_received', 12, 3)->default(0);
            $table->decimal('quantity_accepted', 12, 3)->default(0);
            $table->string('unit')->nullable();
            $table->decimal('estimated_unit_value', 15, 2)->nullable();
            $table->string('value_kind')->default('estimate');
            $table->text('value_justification')->nullable();
            $table->uuid('category_id')->nullable();
            $table->uuid('asset_id')->nullable()->index();
            $table->timestamps();

            $table->index('acquisition_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_request_items');
    }
};
