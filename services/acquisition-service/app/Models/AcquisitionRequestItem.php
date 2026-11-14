<?php

namespace App\Models;

use App\Enums\ValueKind;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcquisitionRequestItem extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'acquisition_request_id', 'description', 'specifications',
        'quantity_ordered', 'quantity_received', 'quantity_accepted',
        'unit', 'estimated_unit_value', 'value_kind', 'value_justification',
        'category_id', 'asset_id',
    ];

    protected function casts(): array
    {
        return [
            'value_kind' => ValueKind::class,
            'quantity_ordered' => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'quantity_accepted' => 'decimal:3',
            'estimated_unit_value' => 'decimal:2',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AcquisitionRequest::class, 'acquisition_request_id');
    }

    public function receptionLines(): HasMany
    {
        return $this->hasMany(AcquisitionReceptionLine::class, 'item_id');
    }

    public function remainingToReceive(): float
    {
        return max(0.0, (float) $this->quantity_ordered - (float) $this->quantity_received);
    }

    public function remainingToAccept(): float
    {
        return max(0.0, (float) $this->quantity_ordered - (float) $this->quantity_accepted);
    }
}
