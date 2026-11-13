<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountRole extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $table = 'account_roles';

    protected $fillable = [
        'functional_account_id',
        'role_id',
        'institution_id',
        'organizational_unit_id',
        'granted_by',
        'granted_at',
        'expires_at',
        'revoked_at',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function functionalAccount(): BelongsTo
    {
        return $this->belongsTo(FunctionalAccount::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
