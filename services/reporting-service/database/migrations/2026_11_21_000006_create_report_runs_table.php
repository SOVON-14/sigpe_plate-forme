<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('template_code', 80)->index();
            $table->uuid('institution_id')->nullable()->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->json('parameters')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('confidentiality', 20)->default('internal');
            $table->uuid('generated_by');
            $table->timestampTz('generated_at')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
