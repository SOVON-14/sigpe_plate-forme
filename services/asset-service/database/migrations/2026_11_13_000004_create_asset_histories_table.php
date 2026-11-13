<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->uuid('institution_id')->nullable();
            $table->string('change_type', 60);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('changed_by')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['asset_id', 'changed_at']);
            $table->index(['institution_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_histories');
    }
};
