<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id')->index();
            $table->string('designation');
            $table->string('type', 30);
            $table->string('status', 20)->default('draft');
            $table->date('period_start');
            $table->date('period_end');
            $table->uuid('responsible_account_id');
            $table->json('organizational_unit_ids')->nullable();
            $table->string('location', 500)->nullable();
            $table->unsignedInteger('expected_count')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_campaigns');
    }
};
