<?php

namespace App\Http\Resources;

use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NotificationPreference */
class NotificationPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'event_type' => $this->event_type,
            'channel' => $this->channel->value,
            'enabled' => $this->enabled,
            'forced' => $this->forced,
        ];
    }
}
