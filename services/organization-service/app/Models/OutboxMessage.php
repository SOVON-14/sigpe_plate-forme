<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'event_type', 'event_version', 'aggregate_type', 'aggregate_id',
        'payload', 'correlation_id', 'causation_id', 'institution_id',
        'actor', 'occurred_at', 'status', 'attempts', 'next_attempt_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'actor' => 'array',
            'occurred_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
