<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    use HasUuidV7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

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

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
    }

    public function scopeClaimable(Builder $query): Builder
    {
        return $query->pending()->orderBy('occurred_at');
    }
}
