<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id')->nullable()->index();
            $table->string('scope', 20)->default('ministerial');
            $table->string('name');
            $table->string('specialty');
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('contract_ref', 255)->nullable();
            $table->date('contract_end_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
