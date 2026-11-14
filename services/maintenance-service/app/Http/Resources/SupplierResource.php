<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'scope' => $this->scope,
            'name' => $this->name,
            'specialty' => $this->specialty,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'address' => $this->address,
            'contract_ref' => $this->contract_ref,
            'contract_end_at' => $this->contract_end_at?->toDateString(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'evaluations' => SupplierEvaluationResource::collection($this->whenLoaded('evaluations')),
        ];
    }
}
