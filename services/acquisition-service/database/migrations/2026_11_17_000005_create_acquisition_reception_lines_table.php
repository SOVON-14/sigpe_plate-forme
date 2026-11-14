<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_reception_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reception_id')->constrained('acquisition_receptions')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('acquisition_request_items')->cascadeOnDelete();
            $table->decimal('quantity_received', 12, 3);
            $table->decimal('quantity_accepted', 12, 3)->default(0);
            $table->decimal('quantity_refused', 12, 3)->default(0);
            $table->boolean('conform')->default(true);
            $table->json('serial_numbers')->nullable();
            $table->string('physical_state_note')->nullable();
            $table->text('reserves')->nullable();
            $table->text('non_conformity_reason')->nullable();
            $table->uuid('asset_id')->nullable();
            $table->json('asset_ids')->nullable();
            $table->timestamps();

            $table->index('reception_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_reception_lines');
    }
};
