<?php

namespace App\Http\Resources;

use App\Models\DocumentAccessRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentAccessRequest */
class AccessRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'requester_id' => $this->requester_id,
            'motif' => $this->motif,
            'scope' => $this->scope,
            'requested_until' => $this->requested_until?->toIso8601String(),
            'status' => $this->status->value,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_motif' => $this->decision_motif,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
