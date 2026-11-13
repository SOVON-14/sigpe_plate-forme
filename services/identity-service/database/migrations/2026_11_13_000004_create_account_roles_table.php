<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('functional_account_id')->constrained('functional_accounts')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->uuid('institution_id')->nullable()->index();
            $table->uuid('organizational_unit_id')->nullable()->index();
            $table->uuid('granted_by')->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['functional_account_id', 'role_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_roles');
    }
};
