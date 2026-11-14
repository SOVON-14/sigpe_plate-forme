<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type');
            $table->uuid('entity_id')->index();
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('actor')->nullable();
            $table->text('motif')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_histories');
    }
};
