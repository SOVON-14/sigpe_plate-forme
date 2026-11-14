<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_rights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->uuid('responsible_institution_id');
            $table->uuid('beneficiary_institution_id');
            $table->string('usage_scope', 500);
            $table->text('responsibilities')->nullable();
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->string('convention_ref', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->uuid('created_by');
            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index(['beneficiary_institution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_rights');
    }
};
