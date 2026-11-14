<?php

namespace App\Models;

use App\Enums\MaintenanceType;
use App\Enums\PlanStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InterventionPlan extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'institution_id', 'asset_id', 'type', 'description', 'trigger',
        'status', 'assigned_supplier_id', 'estimated_cost',
        'last_executed_at', 'next_due_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => MaintenanceType::class,
            'status' => PlanStatus::class,
            'trigger' => 'array',
            'estimated_cost' => 'decimal:2',
            'last_executed_at' => 'datetime',
            'next_due_at' => 'date',
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
