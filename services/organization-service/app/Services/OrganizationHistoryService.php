<?php

namespace App\Services;

use App\Models\OrganizationHistory;
use Illuminate\Support\Str;

class OrganizationHistoryService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $entityType,
        string $entityId,
        string $changeType,
        ?string $institutionId,
        ?array $oldValues,
        ?array $newValues,
        ?string $reason = null,
        ?string $changedBy = null,
    ): OrganizationHistory {
        return OrganizationHistory::create([
            'id' => (string) Str::uuid7(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
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
