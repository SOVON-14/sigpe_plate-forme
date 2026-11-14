<?php

namespace App\Http\Resources;

use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NotificationTemplate */
class NotificationTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'event_type' => $this->event_type,
            'priority' => $this->priority->value,
            'is_mandatory' => $this->is_mandatory,
            'title_template' => $this->title_template,
            'body_template' => $this->body_template,
            'channels' => $this->channels,
            'is_active' => $this->is_active,
        ];
    }
}
