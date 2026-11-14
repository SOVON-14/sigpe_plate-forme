<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HistoryEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'action' => $this->action,
            'before' => $this->before,
            'after' => $this->after,
            'actor' => $this->actor,
            'motif' => $this->motif,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
