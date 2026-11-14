<?php

namespace App\Models;

use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcquisitionRequest extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'reference', 'institution_id', 'organizational_unit_id',
        'requester_account_id', 'title', 'motif', 'priority', 'needed_by',
        'status', 'workflow_instance_id', 'supplier_name', 'supplier_ref',
        'order_ref', 'contracted_total_value', 'total_estimated_value',
        'decided_by', 'decided_at', 'decision_comment',
        'closed_by', 'closed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'priority' => RequestPriority::class,
            'status' => RequestStatus::class,
            'needed_by' => 'datetime',
            'decided_at' => 'datetime',
            'closed_at' => 'datetime',
            'contracted_total_value' => 'decimal:2',
            'total_estimated_value' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AcquisitionRequestItem::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AcquisitionDelivery::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(AcquisitionReception::class);
    }

    public function scopeForInstitution(Builder $query, ?string $institutionId): Builder
    {
        if ($institutionId === null) {
            return $query;
        }

        return $query->where('institution_id', $institutionId);
    }

    public function allItemsAccepted(): bool
    {
        return $this->items->every(
            fn (AcquisitionRequestItem $item) => (float) $item->quantity_accepted >= (float) $item->quantity_ordered
        );
    }
}
