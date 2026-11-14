<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SparePartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'reference' => $this->reference,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'category_id' => $this->category_id,
            'unit_cost' => $this->unit_cost !== null ? (float) $this->unit_cost : null,
            'reorder_threshold' => $this->reorder_threshold !== null ? (float) $this->reorder_threshold : null,
            'is_active' => $this->is_active,
            'stock_by_warehouse' => $this->whenLoaded('stockLevels', fn () => $this->stockLevels->map(fn ($l) => [
                'warehouse_id' => $l->warehouse_id,
                'quantity' => (float) $l->quantity,
            ])->values()),
            'total_quantity' => $this->whenCounted('stockLevels', fn () => $this->totalQuantity()),
            'below_threshold' => $this->isBelowThreshold(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
