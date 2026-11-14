<?php

namespace App\Models;

use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MovementOperation extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'type', 'status', 'institution_id', 'destination_institution_id',
        'origin_unit_id', 'destination_unit_id', 'operation_ref',
        'workflow_instance_id', 'motif', 'loan_start_at', 'loan_end_at',
        'custodian_account_id', 'convention_ref', 'conditions',
        'departure_confirmed_by', 'departure_confirmed_at',
        'reception_confirmed_by', 'reception_confirmed_at',
        'asset_confirmed_at', 'asset_confirmations',
        'incident_type', 'incident_description', 'incident_evidence_refs',
        'holder_type', 'holder_ref', 'custody_role',
        'returned_at', 'decided_by', 'decided_at', 'decision_comment',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => OperationType::class,
            'status' => OperationStatus::class,
            'loan_start_at' => 'datetime',
            'loan_end_at' => 'datetime',
            'departure_confirmed_at' => 'datetime',
            'reception_confirmed_at' => 'datetime',
            'asset_confirmed_at' => 'datetime',
            'asset_confirmations' => 'array',
            'incident_evidence_refs' => 'array',
            'returned_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MovementOperationAsset::class, 'operation_id');
    }

    public function custodies(): HasMany
    {
        return $this->hasMany(CustodyRecord::class, 'source_operation_id');
    }

    public function assetIds(): array
    {
        return $this->assets->pluck('asset_id')->all();
    }

    public function isInterministerial(): bool
    {
        return $this->type === OperationType::InterministerialTransfer;
    }

    public function scopeForInstitution(Builder $query, ?string $institutionId): Builder
    {
        if ($institutionId === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('institution_id', $institutionId)
            ->orWhere('destination_institution_id', $institutionId));
    }
}
