<?php

namespace App\Models;

use App\Enums\CustodyRole;
use App\Enums\HolderType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustodyRecord extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'asset_id', 'institution_id', 'holder_type', 'holder_ref',
        'custody_role', 'valid_from', 'valid_to',
        'source_operation_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'holder_type' => HolderType::class,
            'custody_role' => CustodyRole::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MovementOperation::class, 'source_operation_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('valid_to');
    }

    public function scopeForAsset(Builder $query, string $assetId): Builder
    {
        return $query->where('asset_id', $assetId);
    }
}
