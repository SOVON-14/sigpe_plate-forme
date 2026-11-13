<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\AccountRole;
use App\Models\FunctionalAccount;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccountRoleService
{
    private const PRIVILEGED_ROLE_CODES = [
        'admin_principal',
        'admin_central',
        'admin_ministeriel',
    ];

    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function assign(
        FunctionalAccount $account,
        Role $role,
        ?string $institutionId = null,
        ?string $organizationalUnitId = null,
        ?string $grantedBy = null,
        ?Carbon $expiresAt = null,
        ?string $correlationId = null,
    ): AccountRole {
        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw new DomainException('ROLE_EXPIRY_IN_PAST', 'La date d’expiration doit être dans le futur.');
        }

        $duplicate = AccountRole::where('functional_account_id', $account->id)
            ->where('role_id', $role->id)
            ->where('institution_id', $institutionId)
            ->where('organizational_unit_id', $organizationalUnitId)
            ->whereNull('revoked_at')
            ->exists();

        if ($duplicate) {
            throw new DomainException('ROLE_ALREADY_ASSIGNED', 'Ce rôle est déjà attribué sur ce périmètre.');
        }

        return DB::transaction(function () use ($account, $role, $institutionId, $organizationalUnitId, $grantedBy, $expiresAt, $correlationId) {
            $accountRole = AccountRole::create([
                'functional_account_id' => $account->id,
                'role_id' => $role->id,
                'institution_id' => $institutionId,
                'organizational_unit_id' => $organizationalUnitId,
                'granted_by' => $grantedBy,
                'granted_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $this->outbox->record(
                'identity.role.assigned',
                'account_role',
                $accountRole->id,
                [
                    'functional_account_id' => $account->id,
                    'role_code' => $role->code,
                    'institution_id' => $institutionId,
                    'organizational_unit_id' => $organizationalUnitId,
                    'expires_at' => $expiresAt?->toIso8601String(),
                ],
                $correlationId,
                null,
                $institutionId,
                $grantedBy !== null ? ['type' => 'user', 'id' => $grantedBy] : null,
            );

            return $accountRole;
        });
    }

    public function revoke(
        AccountRole $accountRole,
        string $revokedBy,
        ?string $correlationId = null,
    ): AccountRole {
        if ($accountRole->revoked_at !== null) {
            throw new DomainException('ROLE_ALREADY_REVOKED', 'Cette attribution est déjà révoquée.');
        }

        return DB::transaction(function () use ($accountRole, $revokedBy, $correlationId) {
            $accountRole->revoked_at = now();
            $accountRole->revoked_by = $revokedBy;
            $accountRole->save();

            $this->outbox->record(
                'identity.role.revoked',
                'account_role',
                $accountRole->id,
                [
                    'functional_account_id' => $accountRole->functional_account_id,
                    'role_id' => $accountRole->role_id,
                    'institution_id' => $accountRole->institution_id,
                ],
                $correlationId,
                null,
                $accountRole->institution_id,
                ['type' => 'user', 'id' => $revokedBy],
            );

            return $accountRole;
        });
    }

    public function assertCanGrantPrivileged(
        FunctionalAccount $actor,
        FunctionalAccount $target,
        Role $role,
    ): void {
        if ($actor->id === $target->id && in_array($role->code, self::PRIVILEGED_ROLE_CODES, true)) {
            throw new DomainException(
                'SELF_GRANT_PRIVILEGED',
                'Un utilisateur ne peut pas s’attribuer lui-même des permissions privilégiées.',
            );
        }
    }
}
