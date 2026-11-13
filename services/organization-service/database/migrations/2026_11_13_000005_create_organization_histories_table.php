<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 50); // institution | organizational_unit | position | unit_type
            $table->uuid('entity_id');
            $table->uuid('institution_id')->nullable();
            $table->string('change_type', 50); // created | status_changed | moved | renamed | responsible_changed | type_changed
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('changed_by')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['institution_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_histories');
    }
};
