<?php

namespace App\Http\Resources;

use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipient_account_id' => $this->recipient_account_id,
            'event_type' => $this->event_type,
            'template_code' => $this->template_code,
            'priority' => $this->priority->value,
            'title' => $this->title,
            'body' => $this->body,
            'institution_id' => $this->institution_id,
            'is_mandatory' => $this->is_mandatory,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'acknowledged_by' => $this->acknowledged_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
