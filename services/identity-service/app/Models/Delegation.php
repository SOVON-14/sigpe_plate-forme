<?php

namespace App\Models;

use App\Enums\DelegationStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delegation extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'delegator_functional_account_id',
        'beneficiary_functional_account_id',
        'role_id',
        'institution_id',
        'organizational_unit_id',
        'valid_from',
        'valid_to',
        'status',
        'revoked_at',
        'revoked_by',
        'motif',
    ];

    protected function casts(): array
    {
        return [
            'status' => DelegationStatus::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(FunctionalAccount::class, 'delegator_functional_account_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(FunctionalAccount::class, 'beneficiary_functional_account_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        return $this->status === DelegationStatus::Active
            && $this->valid_from->isPast()
            && $this->valid_to->isFuture();
    }
}
