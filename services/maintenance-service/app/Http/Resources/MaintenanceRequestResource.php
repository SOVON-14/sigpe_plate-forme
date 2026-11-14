<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'institution_id' => $this->institution_id,
            'asset_id' => $this->asset_id,
            'requested_by_unit_id' => $this->requested_by_unit_id,
            'type' => $this->type->value,
            'description' => $this->description,
            'urgency' => $this->urgency,
            'symptoms' => $this->symptoms,
            'estimated_cost' => $this->estimated_cost !== null ? (float) $this->estimated_cost : null,
            'is_urgent_track' => $this->is_urgent_track,
            'urgent_justification' => $this->urgent_justification,
            'status' => $this->status->value,
            'workflow_instance_id' => $this->workflow_instance_id,
            'requester_account_id' => $this->requester_account_id,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'interventions' => InterventionResource::collection($this->whenLoaded('interventions')),
        ];
    }
}
