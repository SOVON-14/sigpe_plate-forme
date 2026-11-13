<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'organizational_unit_id' => $this->organizational_unit_id,
            'category_id' => $this->category_id,
            'subcategory_id' => $this->subcategory_id,
            'patrimonial_code' => $this->patrimonial_code,
            'designation' => $this->designation,
            'description' => $this->description,
            'entry_date' => $this->entry_date?->toDateString(),
            'entry_mode' => $this->entry_mode->value,
            'entry_reference' => $this->entry_reference,
            'physical_state' => $this->physical_state->value,
            'administrative_status' => $this->administrative_status->value,
            'location' => $this->location,
            'qr_token' => $this->qr_token,
            'technical_attributes' => $this->technical_attributes,
            'value_unknown' => $this->value_unknown,
            'value_unknown_justification' => $this->value_unknown_justification,
            'registered_by' => $this->registered_by,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
