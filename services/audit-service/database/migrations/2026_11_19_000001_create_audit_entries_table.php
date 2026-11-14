<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->uuid('id')->unique();
            $table->uuid('event_id')->unique();
            $table->string('producer');
            $table->string('event_type');
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->timestampTz('occurred_at');
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->uuid('institution_id')->nullable()->index();
            $table->string('operation')->nullable();
            $table->string('resource_type')->nullable();
            $table->uuid('resource_id')->nullable()->index();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->string('result')->default('success');
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('evidence_hash')->nullable();
            $table->string('sensitivity')->default('normal');
            $table->json('payload');
            $table->string('previous_hash', 64)->nullable();
            $table->string('entry_hash', 64);
            $table->timestampsTz();

            $table->index(['producer', 'event_type']);
            $table->index(['occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
