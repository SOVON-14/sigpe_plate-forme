<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class AssetHistory extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $fillable = [
        'asset_id', 'institution_id', 'change_type',
        'old_values', 'new_values', 'reason', 'changed_by', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'changed_at' => 'datetime',
        ];
    }
}
