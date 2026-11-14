<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'key', 'subject', 'method', 'route', 'request_hash',
        'response_status', 'response_body',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
        ];
    }
}
