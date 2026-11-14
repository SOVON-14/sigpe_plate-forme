<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('event_type', 150)->unique();
            $table->string('priority', 20)->default('ordinary');
            $table->boolean('is_mandatory')->default(false);
            $table->string('title_template', 300);
            $table->text('body_template');
            $table->json('channels')->default('["in_app"]');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
