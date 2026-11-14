<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_receptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')->constrained('acquisition_deliveries')->cascadeOnDelete();
            $table->foreignUuid('acquisition_request_id')->constrained('acquisition_requests')->cascadeOnDelete();
            $table->uuid('received_by')->nullable();
            $table->string('received_by_name')->nullable();
            $table->string('status');
            $table->uuid('created_by');
            $table->timestamps();

            $table->index('delivery_id');
            $table->index('acquisition_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_receptions');
    }
};
