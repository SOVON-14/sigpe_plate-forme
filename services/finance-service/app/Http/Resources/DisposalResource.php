<?php

namespace App\Http\Resources;

use App\Models\Disposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Disposal */
class DisposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'institution_id' => $this->institution_id,
            'disposal_type' => $this->disposal_type->value,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'estimated_value' => $this->estimated_value !== null ? (float) $this->estimated_value : null,
            'currency' => $this->currency,
            'net_book_value' => $this->net_book_value !== null ? (float) $this->net_book_value : null,
            'is_value_determinate' => $this->is_value_determinate,
            'required_competence_level' => $this->required_competence_level->value,
            'workflow_instance_id' => $this->workflow_instance_id,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
