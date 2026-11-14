<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class InboxMessage extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'event_id', 'event_type', 'producer', 'aggregate_id',
        'payload', 'correlation_id', 'processed_at', 'attempts', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
