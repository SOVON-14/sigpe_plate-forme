<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DelegationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delegator_functional_account_id' => $this->delegator_functional_account_id,
            'beneficiary_functional_account_id' => $this->beneficiary_functional_account_id,
            'role_id' => $this->role_id,
            'institution_id' => $this->institution_id,
            'organizational_unit_id' => $this->organizational_unit_id,
            'valid_from' => $this->valid_from->toIso8601String(),
            'valid_to' => $this->valid_to->toIso8601String(),
            'status' => $this->status->value,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->revoked_by,
            'motif' => $this->motif,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
