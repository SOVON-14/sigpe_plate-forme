<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingested_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 150)->index();
            $table->string('producer', 80)->nullable();
            $table->string('aggregate_type', 60)->nullable();
            $table->uuid('aggregate_id')->nullable();
            $table->uuid('asset_id')->nullable()->index();
            $table->uuid('institution_id')->nullable()->index();
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->json('payload');
            $table->timestampTz('ingested_at');

            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingested_events');
    }
};
