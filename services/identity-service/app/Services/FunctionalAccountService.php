<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\DomainException;
use App\Models\FunctionalAccount;
use Illuminate\Support\Facades\DB;

class FunctionalAccountService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function create(
        string $keycloakSub,
        string $displayName,
        ?string $createdBy = null,
        ?string $correlationId = null,
    ): FunctionalAccount {
        return DB::transaction(function () use ($keycloakSub, $displayName, $createdBy, $correlationId) {
            $account = FunctionalAccount::create([
                'keycloak_sub' => $keycloakSub,
                'display_name' => $displayName,
                'status' => AccountStatus::Active,
                'activated_at' => now(),
                'created_by' => $createdBy,
            ]);

            $this->outbox->record(
                'identity.account.created',
                'functional_account',
                $account->id,
                ['keycloak_sub' => $account->keycloak_sub, 'display_name' => $account->display_name, 'status' => $account->status->value],
                $correlationId,
                null,
                null,
                $createdBy !== null ? ['type' => 'account', 'id' => $createdBy] : null,
            );

            return $account;
        });
    }

    public function changeStatus(
        FunctionalAccount $account,
        AccountStatus $target,
        ?string $actorId = null,
        ?string $correlationId = null,
    ): FunctionalAccount {
        return DB::transaction(function () use ($account, $target, $actorId, $correlationId) {
            $from = $account->status;

            if ($from === $target) {
                throw new DomainException('ACCOUNT_STATUS_UNCHANGED', 'Le compte est déjà dans cet état.');
            }

            $account->status = $target;
            match ($target) {
                AccountStatus::Active => $account->activated_at = now(),
                AccountStatus::Suspended => $account->suspended_at = now(),
                AccountStatus::Disabled => null,
            };
            $account->save();

            $this->outbox->record(
                'identity.account.status_changed',
                'functional_account',
                $account->id,
                ['from' => $from->value, 'to' => $target->value],
                $correlationId,
                null,
                null,
                $actorId !== null ? ['type' => 'account', 'id' => $actorId] : null,
            );

            return $account;
        });
    }
}
