<?php

namespace App\Models;

use App\Enums\InterventionStatus;
use App\Enums\MaintenanceType;
use App\Enums\ProviderKind;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Intervention extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'reference', 'institution_id', 'request_id', 'plan_id', 'asset_id',
        'type', 'description', 'provider_kind', 'supplier_id',
        'assigned_account_id', 'status', 'planned_at', 'started_at',
        'completed_at', 'estimated_cost', 'cost_labor', 'cost_parts', 'cost_supplies',
        'cost_transport', 'cost_external_services', 'cost_other',
        'cost_total', 'outcome_note', 'motif', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => MaintenanceType::class,
            'provider_kind' => ProviderKind::class,
            'status' => InterventionStatus::class,
            'planned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_cost' => 'decimal:2',
            'cost_labor' => 'decimal:2',
            'cost_parts' => 'decimal:2',
            'cost_supplies' => 'decimal:2',
            'cost_transport' => 'decimal:2',
            'cost_external_services' => 'decimal:2',
            'cost_other' => 'decimal:2',
            'cost_total' => 'decimal:2',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'request_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InterventionPlan::class, 'plan_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function costs(): array
    {
        return [
            'labor' => (float) ($this->cost_labor ?? 0),
            'parts' => (float) ($this->cost_parts ?? 0),
            'supplies' => (float) ($this->cost_supplies ?? 0),
            'transport' => (float) ($this->cost_transport ?? 0),
            'external_services' => (float) ($this->cost_external_services ?? 0),
            'other' => (float) ($this->cost_other ?? 0),
            'total' => (float) ($this->cost_total ?? 0),
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
