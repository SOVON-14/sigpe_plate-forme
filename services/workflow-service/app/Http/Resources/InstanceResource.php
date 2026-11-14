<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'workflow_version' => $this->workflow_version,
            'institution_id' => $this->institution_id,
            'operation_type' => $this->operation_type->value,
            'operation_ref' => $this->operation_ref,
            'status' => $this->status->value,
            'current_step_group' => $this->current_step_group,
            'context' => $this->context,
            'progress' => $this->when(
                $this->relationLoaded('tasks') || ! $this->exists,
                fn () => $this->progress(),
            ),
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'started_by' => $this->started_by,
            'decided_outcome' => $this->decided_outcome,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
