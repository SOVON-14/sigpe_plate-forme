<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_campaign_agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('functional_account_id');
            $table->uuid('assigned_by');
            $table->timestamp('assigned_at');
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'functional_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_campaign_agents');
    }
};
