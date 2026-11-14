<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizational_unit_id' => $this->organizational_unit_id,
            'code' => $this->code,
            'title' => $this->title,
            'functional_account_id' => $this->functional_account_id,
            'status' => $this->status,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
