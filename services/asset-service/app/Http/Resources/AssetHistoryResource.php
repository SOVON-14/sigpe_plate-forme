<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'institution_id' => $this->institution_id,
            'change_type' => $this->change_type,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'reason' => $this->reason,
            'changed_by' => $this->changed_by,
            'changed_at' => $this->changed_at->toIso8601String(),
        ];
    }
}
