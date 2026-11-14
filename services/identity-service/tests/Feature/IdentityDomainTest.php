<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\DelegationStatus;
use App\Exceptions\DomainException;
use App\Models\AccountRole;
use App\Models\FunctionalAccount;
use App\Models\OutboxMessage;
use App\Models\Role;
use App\Services\AccountRoleService;
use App\Services\DelegationService;
use App\Services\FunctionalAccountService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IdentityDomainTest extends TestCase
{
    use RefreshDatabase;

    private FunctionalAccountService $accounts;
    private AccountRoleService $accountRoles;
    private DelegationService $delegations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->accounts = app(FunctionalAccountService::class);
        $this->accountRoles = app(AccountRoleService::class);
        $this->delegations = app(DelegationService::class);
    }

    public function test_account_creation_emits_uuidv7_and_outbox_event(): void
    {
        $account = $this->accounts->create('kc-sub-1', 'Admin Principal');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $account->id);
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'identity.account.created',
            'aggregate_id' => $account->id,
        ]);
        $this->assertSame(1, OutboxMessage::count());
    }

    public function test_status_change_is_recorded(): void
    {
        $account = $this->accounts->create('kc-sub-2', 'Agent');
        $this->accounts->changeStatus($account, AccountStatus::Suspended);

        $this->assertSame(AccountStatus::Suspended, $account->fresh()->status);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'identity.account.status_changed']);
    }

    public function test_cannot_self_grant_privileged_role(): void
    {
        $account = $this->accounts->create('kc-sub-3', 'Self');
        $role = Role::where('code', 'admin_principal')->firstOrFail();

        try {
            $this->accountRoles->assertCanGrantPrivileged($account, $account, $role);
            $this->fail('DomainException attendue.');
        } catch (DomainException $e) {
            $this->assertSame('SELF_GRANT_PRIVILEGED', $e->errorCode);
        }
    }

    public function test_role_assignment_and_revocation(): void
    {
        $account = $this->accounts->create('kc-sub-4', 'Gestionnaire');
        $role = Role::where('code', 'gestionnaire_patrimoine')->firstOrFail();

        $assigned = $this->accountRoles->assign($account, $role, 'inst-uuid-1', null, 'admin-1');
        $this->assertTrue($assigned->isActive());

        $this->expectException(DomainException::class);
        $this->accountRoles->assign($account->fresh(), $role, 'inst-uuid-1', null, 'admin-1');
    }

    public function test_delegation_requires_delegator_to_hold_role(): void
    {
        $delegator = $this->accounts->create('kc-sub-5', 'Delegant');
        $beneficiary = $this->accounts->create('kc-sub-6', 'Beneficiaire');
        $role = Role::where('code', 'gestionnaire_patrimoine')->firstOrFail();

        try {
            $this->delegations->create(
                $delegator, $beneficiary, $role,
                Carbon::now(), Carbon::now()->addDays(7),
            );
            $this->fail('DomainException attendue.');
        } catch (DomainException $e) {
            $this->assertSame('DELEGATION_ROLE_NOT_HELD', $e->errorCode);
        }
    }

    public function test_delegation_lifecycle(): void
    {
        $delegator = $this->accounts->create('kc-sub-7', 'Delegant');
        $beneficiary = $this->accounts->create('kc-sub-8', 'Beneficiaire');
        $role = Role::where('code', 'gestionnaire_patrimoine')->firstOrFail();

        $this->accountRoles->assign($delegator, $role);
        $delegation = $this->delegations->create(
            $delegator->fresh(), $beneficiary, $role,
            Carbon::now(), Carbon::now()->addDays(7),
            motif: 'Remplacement congé',
        );

        $this->assertSame(DelegationStatus::Active, $delegation->status);
        $this->assertTrue($delegation->isActive());

        $revoked = $this->delegations->revoke($delegation, $delegator->id);
        $this->assertSame(DelegationStatus::Revoked, $revoked->status);
        $this->assertFalse($revoked->fresh()->isActive());
    }

    public function test_outbox_messages_accumulate_per_transaction(): void
    {
        $account = $this->accounts->create('kc-sub-9', 'Multi');
        $role = Role::where('code', 'agent_consultant')->firstOrFail();
        $this->accountRoles->assign($account, $role);

        $this->assertSame(2, OutboxMessage::count());
        $this->assertEqualsCanonicalizing(
            ['identity.account.created', 'identity.role.assigned'],
            OutboxMessage::pluck('event_type')->all(),
        );
    }
}
