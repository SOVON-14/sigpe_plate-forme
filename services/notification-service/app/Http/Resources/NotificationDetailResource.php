<?php

namespace App\Http\Resources;

use App\Models\AppNotification;
use Illuminate\Http\Request;

/** @mixin AppNotification */
class NotificationDetailResource extends NotificationResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'link' => $this->link,
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn ($d) => [
                'id' => $d->id,
                'notification_id' => $d->notification_id,
                'channel' => $d->channel->value,
                'status' => $d->status->value,
                'attempts' => $d->attempts,
                'last_error' => $d->last_error,
                'sent_at' => $d->sent_at?->toIso8601String(),
                'delivered_at' => $d->delivered_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values()->all()),
        ]);
    }
}
