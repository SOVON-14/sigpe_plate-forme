<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcquisitionReceptionLine extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'reception_id', 'item_id', 'quantity_received', 'quantity_accepted',
        'quantity_refused', 'conform', 'serial_numbers', 'physical_state_note',
        'reserves', 'non_conformity_reason', 'asset_id', 'asset_ids',
    ];

    protected function casts(): array
    {
        return [
            'conform' => 'boolean',
            'serial_numbers' => 'array',
            'quantity_received' => 'decimal:3',
            'quantity_accepted' => 'decimal:3',
            'quantity_refused' => 'decimal:3',
            'asset_ids' => 'array',
        ];
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(AcquisitionReception::class, 'reception_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AcquisitionRequestItem::class, 'item_id');
    }
}
