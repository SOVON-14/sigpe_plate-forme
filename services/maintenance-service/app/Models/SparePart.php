<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SparePart extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'institution_id', 'reference', 'name', 'description', 'unit',
        'category_id', 'unit_cost', 'reorder_threshold', 'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'reorder_threshold' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function scopeForInstitution(Builder $query, ?string $institutionId): Builder
    {
        if ($institutionId === null) {
            return $query;
        }

        return $query->where('institution_id', $institutionId);
    }

    public function totalQuantity(): float
    {
        return (float) $this->stockLevels()->sum('quantity');
    }

    public function isBelowThreshold(): bool
    {
        if ($this->reorder_threshold === null) {
            return false;
        }

        return $this->totalQuantity() < (float) $this->reorder_threshold;
    }
}
