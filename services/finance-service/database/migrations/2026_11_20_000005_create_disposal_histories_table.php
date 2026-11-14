<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disposal_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('disposal_id')->index();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->uuid('actor_id');
            $table->text('comment')->nullable();
            $table->timestampsTz();

            $table->index(['disposal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disposal_histories');
    }
};
