<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MaintenanceHistory extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'entity_type', 'entity_id', 'action', 'before', 'after',
        'actor', 'motif', 'correlation_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'actor' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function scopeForEntity(Builder $query, string $entityType, string $entityId): Builder
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }
}
