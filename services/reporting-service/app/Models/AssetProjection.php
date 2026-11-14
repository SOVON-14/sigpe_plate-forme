<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetProjection extends Model
{
    protected $table = 'asset_projections';

    protected $primaryKey = 'asset_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'asset_id', 'patrimonial_code', 'designation', 'category_id',
        'institution_id', 'state', 'entry_mode', 'value_known',
        'last_event_type', 'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'value_known' => 'boolean',
            'last_event_at' => 'datetime',
        ];
    }
}
