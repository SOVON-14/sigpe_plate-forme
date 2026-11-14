<?php

namespace App\Http\Resources;

use App\Models\DocumentAccessLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentAccessLog */
class AccessLogEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'version_id' => $this->version_id,
            'actor_id' => $this->actor_id,
            'action' => $this->action->value,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
