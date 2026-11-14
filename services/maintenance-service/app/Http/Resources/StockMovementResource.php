<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'spare_part_id' => $this->spare_part_id,
            'warehouse_id' => $this->warehouse_id,
            'type' => $this->type->value,
            'quantity' => (float) $this->quantity,
            'target_warehouse_id' => $this->target_warehouse_id,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'unit_cost' => $this->unit_cost !== null ? (float) $this->unit_cost : null,
            'actor' => $this->actor,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
