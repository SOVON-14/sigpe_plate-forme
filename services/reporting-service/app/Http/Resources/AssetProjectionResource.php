<?php

namespace App\Http\Resources;

use App\Models\AssetProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetProjection */
class AssetProjectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'asset_id' => $this->asset_id,
            'patrimonial_code' => $this->patrimonial_code,
            'designation' => $this->designation,
            'category_id' => $this->category_id,
            'institution_id' => $this->institution_id,
            'state' => $this->state,
            'entry_mode' => $this->entry_mode,
            'value_known' => $this->value_known,
            'last_event_type' => $this->last_event_type,
            'last_event_at' => $this->last_event_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
