<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'instance_id' => $this->instance_id,
            'step_id' => $this->step_id,
            'step_group' => $this->step_group,
            'step_name' => $this->step_name,
            'assignee_type' => $this->assignee_type->value,
            'assignee_ref' => $this->assignee_ref,
            'mode' => $this->mode->value,
            'sla_hours' => $this->sla_hours,
            'sla_due_at' => $this->sla_due_at?->toIso8601String(),
            'status' => $this->status->value,
            'is_delegated' => $this->is_delegated,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
