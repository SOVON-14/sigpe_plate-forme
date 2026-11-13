<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'functional_account_id' => $this->functional_account_id,
            'role_id' => $this->role_id,
            'institution_id' => $this->institution_id,
            'organizational_unit_id' => $this->organizational_unit_id,
            'granted_by' => $this->granted_by,
            'granted_at' => $this->granted_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->revoked_by,
        ];
    }
}
