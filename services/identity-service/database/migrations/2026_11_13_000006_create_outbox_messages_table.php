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
            $table->string('event_type');
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->json('payload');
            $table->uuid('correlation_id');
            $table->uuid('causation_id')->nullable();
            $table->uuid('institution_id')->nullable();
            $table->json('actor')->nullable();
            $table->timestamp('occurred_at');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'occurred_at']);
            $table->index('aggregate_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
