<?php

namespace App\Models;

use App\Enums\NotificationPriority;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'code', 'event_type', 'priority', 'is_mandatory',
        'title_template', 'body_template', 'channels', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'priority' => NotificationPriority::class,
            'is_mandatory' => 'boolean',
            'channels' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
