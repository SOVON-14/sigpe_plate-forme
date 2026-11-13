<?php

namespace App\Services;

use App\Enums\DelegationStatus;
use App\Exceptions\DomainException;
use App\Models\Delegation;
use App\Models\FunctionalAccount;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DelegationService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function create(
        FunctionalAccount $delegator,
        FunctionalAccount $beneficiary,
        ?Role $role,
        Carbon $validFrom,
        Carbon $validTo,
        ?string $institutionId = null,
        ?string $organizationalUnitId = null,
        ?string $motif = null,
        ?string $correlationId = null,
    ): Delegation {
        if ($delegator->id === $beneficiary->id) {
            throw new DomainException('DELEGATION_SELF', 'Un compte ne peut pas se déléguer à lui-même.');
        }

        if ($validTo->lessThanOrEqualTo($validFrom)) {
            throw new DomainException('DELEGATION_INVALID_PERIOD', 'La fin de délégation doit être postérieure au début.');
        }

        if ($role !== null && ! $this->delegatorHoldsRole($delegator, $role, $institutionId, $organizationalUnitId)) {
            throw new DomainException(
                'DELEGATION_ROLE_NOT_HELD',
                'Le délégant doit détenir le rôle délégué sur le périmètre concerné.',
            );
        }

        return DB::transaction(function () use ($delegator, $beneficiary, $role, $validFrom, $validTo, $institutionId, $organizationalUnitId, $motif, $correlationId) {
            $delegation = Delegation::create([
                'delegator_functional_account_id' => $delegator->id,
                'beneficiary_functional_account_id' => $beneficiary->id,
                'role_id' => $role?->id,
                'institution_id' => $institutionId,
                'organizational_unit_id' => $organizationalUnitId,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'status' => DelegationStatus::Active,
                'motif' => $motif,
            ]);

            $this->outbox->record(
                'identity.delegation.created',
                'delegation',
                $delegation->id,
                [
                    'delegator_functional_account_id' => $delegator->id,
                    'beneficiary_functional_account_id' => $beneficiary->id,
                    'role_id' => $role?->id,
                    'institution_id' => $institutionId,
                    'organizational_unit_id' => $organizationalUnitId,
                    'valid_from' => $validFrom->toIso8601String(),
                    'valid_to' => $validTo->toIso8601String(),
                ],
                $correlationId,
                null,
                $institutionId,
                ['type' => 'user', 'id' => $delegator->id],
            );

            return $delegation;
        });
    }

    public function revoke(
        Delegation $delegation,
        string $revokedBy,
        ?string $correlationId = null,
    ): Delegation {
        if ($delegation->status !== DelegationStatus::Active) {
            throw new DomainException('DELEGATION_NOT_ACTIVE', 'Seule une délégation active peut être révoquée.');
        }

        return DB::transaction(function () use ($delegation, $revokedBy, $correlationId) {
            $delegation->status = DelegationStatus::Revoked;
            $delegation->revoked_at = now();
            $delegation->revoked_by = $revokedBy;
            $delegation->save();

            $this->outbox->record(
                'identity.delegation.revoked',
                'delegation',
                $delegation->id,
                [
                    'delegator_functional_account_id' => $delegation->delegator_functional_account_id,
                    'beneficiary_functional_account_id' => $delegation->beneficiary_functional_account_id,
                    'institution_id' => $delegation->institution_id,
                ],
                $correlationId,
                null,
                $delegation->institution_id,
                ['type' => 'user', 'id' => $revokedBy],
            );

            return $delegation;
        });
    }

    private function delegatorHoldsRole(
        FunctionalAccount $delegator,
        Role $role,
        ?string $institutionId,
        ?string $organizationalUnitId,
    ): bool {
        return $delegator->accountRoles()
            ->where('role_id', $role->id)
            ->where('institution_id', $institutionId)
            ->where('organizational_unit_id', $organizationalUnitId)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
