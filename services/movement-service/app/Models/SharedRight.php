<?php

namespace App\Models;

use App\Enums\SharedRightStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SharedRight extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'asset_id', 'responsible_institution_id', 'beneficiary_institution_id',
        'usage_scope', 'responsibilities', 'valid_from', 'valid_to',
        'convention_ref', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SharedRightStatus::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    public function scopeVisibleTo(Builder $query, ?string $institutionId): Builder
    {
        if ($institutionId === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('responsible_institution_id', $institutionId)
            ->orWhere('beneficiary_institution_id', $institutionId));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SharedRightStatus::Active);
    }
}
