<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'parent_workflow_id' => $this->parent_workflow_id,
            'domain' => $this->domain->value,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'version' => $this->version,
            'status' => $this->status->value,
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn ($s) => [
                'id' => $s->id,
                'step_group' => $s->step_group,
                'sequence' => $s->sequence,
                'name' => $s->name,
                'assignee_type' => $s->assignee_type->value,
                'assignee_ref' => $s->assignee_ref,
                'mode' => $s->mode->value,
                'sla_hours' => $s->sla_hours,
                'is_national_obligatory' => $s->is_national_obligatory,
            ])->values()),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
