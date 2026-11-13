<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationalUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'parent_id' => $this->parent_id,
            'unit_type_id' => $this->unit_type_id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status->value,
            'head_position_id' => $this->head_position_id,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
