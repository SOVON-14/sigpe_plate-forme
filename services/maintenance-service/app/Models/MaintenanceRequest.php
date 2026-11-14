<?php

namespace App\Models;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceRequest extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'reference', 'institution_id', 'asset_id', 'requested_by_unit_id',
        'type', 'description', 'urgency', 'symptoms', 'estimated_cost',
        'is_urgent_track', 'urgent_justification', 'status',
        'workflow_instance_id', 'requester_account_id',
        'decided_by', 'decided_at', 'decision_comment', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => MaintenanceType::class,
            'status' => MaintenanceRequestStatus::class,
            'is_urgent_track' => 'boolean',
            'estimated_cost' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class, 'request_id');
    }

    public function scopeForInstitution(Builder $query, ?string $institutionId): Builder
    {
        if ($institutionId === null) {
            return $query;
        }

        return $query->where('institution_id', $institutionId);
    }
}
