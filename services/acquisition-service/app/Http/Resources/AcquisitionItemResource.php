<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcquisitionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'specifications' => $this->specifications,
            'quantity_ordered' => (float) $this->quantity_ordered,
            'quantity_received' => (float) $this->quantity_received,
            'quantity_accepted' => (float) $this->quantity_accepted,
            'unit' => $this->unit,
            'estimated_unit_value' => $this->estimated_unit_value !== null ? (float) $this->estimated_unit_value : null,
            'value_kind' => $this->value_kind->value,
            'value_justification' => $this->value_justification,
            'category_id' => $this->category_id,
            'asset_id' => $this->asset_id,
        ];
    }
}
