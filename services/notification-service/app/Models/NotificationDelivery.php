<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'notification_id', 'channel', 'status', 'attempts',
        'last_error', 'sent_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(AppNotification::class, 'notification_id');
    }
}
