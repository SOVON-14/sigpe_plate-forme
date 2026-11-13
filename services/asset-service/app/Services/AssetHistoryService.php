<?php

namespace App\Services;

use App\Models\AssetHistory;
use Illuminate\Support\Str;

class AssetHistoryService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $assetId,
        string $changeType,
        ?string $institutionId,
        ?array $oldValues,
        ?array $newValues,
        ?string $reason = null,
        ?string $changedBy = null,
    ): AssetHistory {
        return AssetHistory::create([
            'id' => (string) Str::uuid7(),
            'asset_id' => $assetId,
            'institution_id' => $institutionId,
            'change_type' => $changeType,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'changed_by' => $changedBy,
            'changed_at' => now(),
        ]);
    }
}
