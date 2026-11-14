<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\AccountRole;
use App\Models\Delegation;
use App\Models\FunctionalAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Services\EffectiveAccessService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EffectiveAccessTest extends TestCase
{
    use RefreshDatabase;

    private EffectiveAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->access = app(EffectiveAccessService::class);
    }

    private function accountWithRole(string $sub, string $roleCode, ?string $institutionId = null): array
    {
        $account = FunctionalAccount::create([
            'keycloak_sub' => $sub,
            'display_name' => $sub,
            'status' => AccountStatus::Active->value,
        ]);
        $role = Role::where('code', $roleCode)->firstOrFail();
        $permission = Permission::create([
            'code' => $roleCode . '.read',
            'domain' => 'test',
            'action' => 'read',
        ]);
        $role->permissions()->attach($permission->id);
        AccountRole::create([
            'functional_account_id' => $account->id,
            'role_id' => $role->id,
            'institution_id' => $institutionId,
            'granted_at' => now(),
        ]);

        return [$account, $role, $permission];
    }

    public function test_permission_scoped_to_institution(): void
    {
        [$account] = $this->accountWithRole('kc-a', 'gestionnaire_patrimoine', 'inst-A');

        $this->assertTrue($this->access->can($account, 'gestionnaire_patrimoine.read', 'inst-A'));
        $this->assertFalse($this->access->can($account, 'gestionnaire_patrimoine.read', 'inst-B'));
    }

    public function test_national_scope_matches_any_institution(): void
    {
        [$account] = $this->accountWithRole('kc-b', 'admin_central', null);

        $this->assertTrue($this->access->can($account, 'admin_central.read', 'inst-X'));
        $this->assertTrue($this->access->can($account, 'admin_central.read'));
    }

    public function test_suspended_account_has_no_permissions(): void
    {
        [$account] = $this->accountWithRole('kc-c', 'agent_consultant');
        $account->status = AccountStatus::Suspended;
        $account->save();

        $this->assertCount(0, $this->access->permissionsFor($account));
    }

    public function test_expired_role_is_ignored(): void
    {
        [$account, , $permission] = $this->accountWithRole('kc-d', 'agent_inventaire');
        AccountRole::where('functional_account_id', $account->id)
            ->update(['expires_at' => Carbon::now()->subDay()]);

        $this->assertFalse($this->access->can($account, 'agent_inventaire.read'));
    }

    public function test_active_delegation_grants_role_permissions(): void
    {
        [$delegator, $role] = $this->accountWithRole('kc-e', 'gestionnaire_patrimoine', 'inst-A');
        $beneficiary = FunctionalAccount::create([
            'keycloak_sub' => 'kc-f',
            'display_name' => 'benef',
            'status' => AccountStatus::Active->value,
        ]);

        Delegation::create([
            'delegator_functional_account_id' => $delegator->id,
            'beneficiary_functional_account_id' => $beneficiary->id,
            'role_id' => $role->id,
            'institution_id' => 'inst-A',
            'valid_from' => Carbon::now()->subHour(),
            'valid_to' => Carbon::now()->addDay(),
            'status' => 'active',
        ]);

        $this->assertTrue($this->access->can($beneficiary, 'gestionnaire_patrimoine.read', 'inst-A'));
        $this->assertFalse($this->access->can($beneficiary, 'gestionnaire_patrimoine.read', 'inst-B'));
    }

    public function test_revoked_delegation_grants_nothing(): void
    {
        [$delegator, $role] = $this->accountWithRole('kc-g', 'agent_consultant');
        $beneficiary = FunctionalAccount::create([
            'keycloak_sub' => 'kc-h',
            'display_name' => 'benef2',
            'status' => AccountStatus::Active->value,
        ]);

        Delegation::create([
            'delegator_functional_account_id' => $delegator->id,
            'beneficiary_functional_account_id' => $beneficiary->id,
            'role_id' => $role->id,
            'valid_from' => Carbon::now()->subHour(),
            'valid_to' => Carbon::now()->addDay(),
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->assertCount(0, $this->access->permissionsFor($beneficiary));
    }
}
