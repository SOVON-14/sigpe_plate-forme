<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Entree de journal d'audit — IMMUTABLE par conception (CDC §4.13,
 * catalogue v1.1 §8.4). Aucune mise a jour ni suppression : une
 * correction est une nouvelle entree, jamais une modification
 * silencieuse.
 */
class AuditEntry extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'event_id',
        'producer',
        'event_type',
        'event_version',
        'occurred_at',
        'actor_id',
        'actor_type',
        'institution_id',
        'operation',
        'resource_type',
        'resource_id',
        'old_value',
        'new_value',
        'reason',
        'result',
        'correlation_id',
        'evidence_hash',
        'sensitivity',
        'payload',
        'previous_hash',
        'entry_hash',
    ];

    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'occurred_at' => 'datetime',
            'old_value' => 'array',
            'new_value' => 'array',
            'payload' => 'array',
        ];
    }

    public function getKeyName(): string
    {
        return 'id';
    }
}
