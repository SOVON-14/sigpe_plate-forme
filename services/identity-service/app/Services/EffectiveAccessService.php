<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\AccountRole;
use App\Models\Delegation;
use App\Models\FunctionalAccount;
use Illuminate\Support\Collection;

class EffectiveAccessService
{
    public function permissionsFor(
        FunctionalAccount $account,
        ?string $institutionId = null,
        ?string $organizationalUnitId = null,
    ): Collection {
        if (! $account->isActive()) {
            return collect();
        }

        $direct = AccountRole::query()
            ->where('functional_account_id', $account->id)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $this->scopeMatches($q, 'account_roles', $institutionId, $organizationalUnitId))
            ->with('role.permissions')
            ->get()
            ->flatMap(fn (AccountRole $ar) => $ar->role->permissions->pluck('code'));

        $delegated = Delegation::query()
            ->where('beneficiary_functional_account_id', $account->id)
            ->where('status', 'active')
            ->where('valid_from', '<=', now())
            ->where('valid_to', '>', now())
            ->whereNotNull('role_id')
            ->where(fn ($q) => $this->scopeMatches($q, 'delegations', $institutionId, $organizationalUnitId))
            ->with('role.permissions')
            ->get()
            ->flatMap(fn (Delegation $d) => $d->role->permissions->pluck('code'));

        return $direct->merge($delegated)->unique()->values();
    }

    public function can(
        FunctionalAccount $account,
        string $permissionCode,
        ?string $institutionId = null,
        ?string $organizationalUnitId = null,
    ): bool {
        return $this->permissionsFor($account, $institutionId, $organizationalUnitId)
            ->contains($permissionCode);
    }

    private function scopeMatches($query, string $table, ?string $institutionId, ?string $organizationalUnitId)
    {
        return $query
            ->where(function ($q) use ($table, $institutionId) {
                $q->whereNull("{$table}.institution_id");
                if ($institutionId !== null) {
                    $q->orWhere("{$table}.institution_id", $institutionId);
                }
            })
            ->where(function ($q) use ($table, $organizationalUnitId) {
                $q->whereNull("{$table}.organizational_unit_id");
                if ($organizationalUnitId !== null) {
                    $q->orWhere("{$table}.organizational_unit_id", $organizationalUnitId);
                }
            });
    }
}
