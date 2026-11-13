<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'event_type',
    'event_version',
    'aggregate_type',
    'aggregate_id',
    'payload',
    'correlation_id',
    'causation_id',
    'institution_id',
    'actor',
    'occurred_at',
    'status',
    'attempts',
    'next_attempt_at',
    'published_at',
])]
class OutboxMessage extends Model
{
    use HasUuids;

    protected $table = 'outbox_messages';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'payload' => 'array',
            'actor' => 'array',
            'occurred_at' => 'datetime',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
