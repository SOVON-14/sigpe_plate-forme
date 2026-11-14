<?php

namespace App\Http\Resources;

use App\Models\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditEntry */
class AuditEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'producer' => $this->producer,
            'event_type' => $this->event_type,
            'event_version' => $this->event_version,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'actor_id' => $this->actor_id,
            'actor_type' => $this->actor_type,
            'institution_id' => $this->institution_id,
            'operation' => $this->operation,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'reason' => $this->reason,
            'result' => $this->result,
            'correlation_id' => $this->correlation_id,
            'evidence_hash' => $this->evidence_hash,
            'entry_hash' => $this->entry_hash,
            'previous_hash' => $this->previous_hash,
            'sequence' => $this->seq,
            'sensitivity' => $this->sensitivity,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
