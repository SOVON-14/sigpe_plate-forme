<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'institution_id', 'spare_part_id', 'warehouse_id', 'type',
        'quantity', 'target_warehouse_id', 'reason', 'reference',
        'unit_cost', 'actor', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function scopeForInstitution(Builder $query, ?string $institutionId): Builder
    {
        if ($institutionId === null) {
            return $query;
        }

        return $query->where('institution_id', $institutionId);
    }
}
