<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('notification_id')->index();
            $table->string('channel', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique(['notification_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
