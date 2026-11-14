<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class MovementHistory extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'entity_type', 'entity_id', 'action', 'before', 'after',
        'actor', 'motif', 'occurred_at',
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
}
