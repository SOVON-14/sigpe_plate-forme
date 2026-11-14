<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary(); // asset_id — stable et immuable (catalogue §4.2)
            $table->uuid('institution_id');
            $table->uuid('organizational_unit_id')->nullable();
            $table->uuid('category_id');
            $table->uuid('subcategory_id')->nullable();
            $table->string('patrimonial_code', 50);
            $table->string('designation', 255);
            $table->text('description')->nullable();
            $table->date('entry_date');
            $table->string('entry_mode', 30);
            $table->string('entry_reference')->nullable();
            $table->string('physical_state', 20);
            $table->string('administrative_status', 20)->default('actif');
            $table->text('location')->nullable();
            $table->string('qr_token', 64)->nullable()->unique();
            $table->json('technical_attributes')->nullable();
            $table->boolean('value_unknown')->default(false);
            $table->text('value_unknown_justification')->nullable();
            $table->uuid('registered_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['institution_id', 'patrimonial_code'], 'assets_patrimonial_unique');
            $table->index(['institution_id', 'administrative_status']);
            $table->index(['institution_id', 'organizational_unit_id']);
            $table->index(['category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
