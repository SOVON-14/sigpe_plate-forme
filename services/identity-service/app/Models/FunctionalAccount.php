<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FunctionalAccount extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'keycloak_sub',
        'display_name',
        'status',
        'activated_at',
        'suspended_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function accountRoles(): HasMany
    {
        return $this->hasMany(AccountRole::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'account_roles')
            ->withPivot(['institution_id', 'organizational_unit_id', 'granted_by', 'granted_at', 'expires_at', 'revoked_at', 'revoked_by'])
            ->withTimestamps();
    }

    public function delegationsGiven(): HasMany
    {
        return $this->hasMany(Delegation::class, 'delegator_functional_account_id');
    }

    public function delegationsReceived(): HasMany
    {
        return $this->hasMany(Delegation::class, 'beneficiary_functional_account_id');
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }
}
