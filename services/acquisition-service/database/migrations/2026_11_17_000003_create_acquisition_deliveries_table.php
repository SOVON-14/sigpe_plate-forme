<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('acquisition_request_id')->constrained('acquisition_requests')->cascadeOnDelete();
            $table->string('delivery_note_ref')->nullable();
            $table->string('carrier')->nullable();
            $table->timestamp('expected_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->string('status')->default('expected')->index();
            $table->uuid('created_by');
            $table->timestamps();

            $table->index('acquisition_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_deliveries');
    }
};
