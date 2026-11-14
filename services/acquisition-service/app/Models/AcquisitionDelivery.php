<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcquisitionDelivery extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'acquisition_request_id', 'delivery_note_ref', 'carrier',
        'expected_at', 'arrived_at', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'expected_at' => 'datetime',
            'arrived_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AcquisitionRequest::class, 'acquisition_request_id');
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(AcquisitionReception::class, 'delivery_id');
    }

    public function scopeForRequest($query, string $requestId)
    {
        return $query->where('acquisition_request_id', $requestId);
    }
}
