<?php

namespace App\Models;

use App\Enums\ReceptionStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcquisitionReception extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'delivery_id', 'acquisition_request_id', 'received_by',
        'received_by_name', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReceptionStatus::class,
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(AcquisitionDelivery::class, 'delivery_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AcquisitionRequest::class, 'acquisition_request_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AcquisitionReceptionLine::class, 'reception_id');
    }
}
