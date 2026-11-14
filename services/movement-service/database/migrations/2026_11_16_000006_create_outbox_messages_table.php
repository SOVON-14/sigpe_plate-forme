<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 100);
            $table->unsignedTinyInteger('event_version')->default(1);
            $table->string('aggregate_type', 100);
            $table->uuid('aggregate_id');
            $table->json('payload');
            $table->string('correlation_id', 64)->nullable();
            $table->string('causation_id', 64)->nullable();
            $table->uuid('institution_id')->nullable();
            $table->json('actor')->nullable();
            $table->timestamp('occurred_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
