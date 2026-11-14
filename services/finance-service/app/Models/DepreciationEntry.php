<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class DepreciationEntry extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'asset_id', 'institution_id', 'period_start', 'period_end',
        'amount', 'method', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount' => 'decimal:2',
        ];
    }
}
