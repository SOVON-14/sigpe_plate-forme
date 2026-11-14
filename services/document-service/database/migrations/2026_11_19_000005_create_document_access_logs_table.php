<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->index();
            $table->uuid('version_id')->nullable();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('action', 30);
            $table->string('ip_address', 60)->nullable();
            $table->uuid('access_request_id')->nullable();
            $table->timestampsTz();

            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_logs');
    }
};
