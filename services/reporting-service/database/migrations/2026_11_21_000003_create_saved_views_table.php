<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->index();
            $table->string('name', 120);
            $table->string('profile', 60)->default('default');
            $table->json('filters');
            $table->timestampsTz();

            $table->unique(['account_id', 'name', 'profile']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
