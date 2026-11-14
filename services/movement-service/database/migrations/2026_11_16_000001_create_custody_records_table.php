<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->uuid('institution_id');
            $table->string('holder_type', 30);
            $table->uuid('holder_ref');
            $table->string('custody_role', 40);
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->uuid('source_operation_id')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->index(['asset_id', 'valid_to']);
            $table->index(['institution_id', 'valid_to']);
            $table->index(['holder_type', 'holder_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_records');
    }
};
