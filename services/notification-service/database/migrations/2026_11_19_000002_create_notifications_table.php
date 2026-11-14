<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipient_account_id')->index();
            $table->string('event_type', 150)->index();
            $table->uuid('source_event_id')->nullable();
            $table->uuid('template_id')->nullable();
            $table->string('template_code', 80)->nullable();
            $table->string('priority', 20)->default('ordinary')->index();
            $table->string('title', 300);
            $table->text('body');
            $table->string('link', 1000)->nullable();
            $table->uuid('institution_id')->nullable()->index();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_read')->default(false);
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->text('acknowledgment_comment')->nullable();
            $table->timestampsTz();

            $table->index(['recipient_account_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
