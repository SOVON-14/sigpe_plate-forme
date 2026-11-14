<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delegator_functional_account_id')->constrained('functional_accounts')->restrictOnDelete();
            $table->foreignUuid('beneficiary_functional_account_id')->constrained('functional_accounts')->restrictOnDelete();
            $table->foreignUuid('role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->uuid('institution_id')->nullable()->index();
            $table->uuid('organizational_unit_id')->nullable()->index();
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->string('status')->default('active')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->string('motif')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['beneficiary_functional_account_id', 'status']);
            $table->index(['valid_from', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
