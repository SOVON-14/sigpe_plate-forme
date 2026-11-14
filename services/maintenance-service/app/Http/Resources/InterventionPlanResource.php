<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterventionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'asset_id' => $this->asset_id,
            'type' => $this->type->value,
            'description' => $this->description,
            'trigger' => $this->trigger,
            'status' => $this->status->value,
            'assigned_supplier_id' => $this->assigned_supplier_id,
            'estimated_cost' => $this->estimated_cost !== null ? (float) $this->estimated_cost : null,
            'last_executed_at' => $this->last_executed_at?->toIso8601String(),
            'next_due_at' => $this->next_due_at?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
