<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class IngestedEvent extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $table = 'ingested_events';

    protected $fillable = [
        'event_id', 'event_type', 'producer', 'aggregate_type', 'aggregate_id',
        'asset_id', 'institution_id', 'correlation_id', 'occurred_at', 'payload', 'ingested_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'ingested_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
