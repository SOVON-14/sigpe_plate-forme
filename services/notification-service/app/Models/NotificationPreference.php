<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'account_id', 'event_type', 'channel', 'enabled', 'forced',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'enabled' => 'boolean',
            'forced' => 'boolean',
        ];
    }
}
