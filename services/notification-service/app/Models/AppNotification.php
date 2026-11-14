<?php

namespace App\Models;

use App\Enums\NotificationPriority;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppNotification extends Model
{
    use HasUuidV7;

    protected $table = 'notifications';

    protected $fillable = [
        'recipient_account_id', 'event_type', 'source_event_id',
        'template_id', 'template_code', 'priority', 'title', 'body',
        'link', 'institution_id', 'is_mandatory', 'is_read', 'read_at',
        'acknowledged_at', 'acknowledged_by', 'acknowledgment_comment',
    ];

    protected function casts(): array
    {
        return [
            'priority' => NotificationPriority::class,
            'is_mandatory' => 'boolean',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }
}
