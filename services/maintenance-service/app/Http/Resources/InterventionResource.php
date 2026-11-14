<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterventionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'institution_id' => $this->institution_id,
            'request_id' => $this->request_id,
            'plan_id' => $this->plan_id,
            'asset_id' => $this->asset_id,
            'type' => $this->type->value,
            'description' => $this->description,
            'provider_kind' => $this->provider_kind->value,
            'supplier_id' => $this->supplier_id,
            'assigned_account_id' => $this->assigned_account_id,
            'status' => $this->status->value,
            'planned_at' => $this->planned_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'estimated_cost' => $this->estimated_cost !== null ? (float) $this->estimated_cost : null,
            'costs' => $this->cost_total !== null ? $this->costs() : null,
            'outcome_note' => $this->outcome_note,
            'motif' => $this->motif,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
