<?php

namespace Tests\Feature;

use App\Models\AccountRole;
use App\Models\FunctionalAccount;
use App\Models\IdempotencyKey;
use App\Models\Permission;
use App\Models\Role;
use App\Services\TokenValidator;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityApiTest extends TestCase
{
    use RefreshDatabase;

    private FunctionalAccount $admin;
    private string $token = 'fake.jwt.token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $sub = 'kc-admin-sub';
        $this->app->instance(TokenValidator::class, new class($sub) implements TokenValidator {
            public function __construct(private string $sub) {}
            public function validate(string $token): string { return $this->sub; }
        });

        $this->admin = FunctionalAccount::create([
            'keycloak_sub' => $sub,
            'display_name' => 'Admin Test',
            'status' => 'active',
            'activated_at' => now(),
        ]);

        $codes = [
            'identity.accounts.read', 'identity.accounts.manage',
            'identity.roles.read', 'identity.delegations.read', 'identity.delegations.manage',
        ];
        $role = Role::where('code', 'admin_principal')->firstOrFail();
        foreach ($codes as $code) {
            $perm = Permission::create(['code' => $code, 'domain' => 'identity', 'action' => Str::after($code, '.')]);
            $role->permissions()->attach($perm->id);
        }
        AccountRole::create([
            'functional_account_id' => $this->admin->id,
            'role_id' => $role->id,
            'granted_at' => now(),
        ]);
    }

    private function auth(array $headers = []): array
    {
        return ['Authorization' => 'Bearer ' . $this->token] + $headers;
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'identity-service');
    }

    public function test_endpoints_require_token(): void
    {
        $this->getJson('/api/v1/accounts')->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_account_crud_flow(): void
    {
        $create = $this->postJson('/api/v1/accounts', [
            'keycloak_sub' => 'kc-new-1',
            'display_name' => 'Agent Test',
        ], $this->auth(['Idempotency-Key' => 'test-key-12345']));

        $create->assertCreated()
            ->assertJsonPath('data.keycloak_sub', 'kc-new-1')
            ->assertJsonPath('data.status', 'active');

        $id = $create->json('data.id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);

        $this->getJson("/api/v1/accounts/{$id}", $this->auth())->assertOk()
            ->assertJsonPath('data.display_name', 'Agent Test');

        $this->getJson('/api/v1/accounts', $this->auth())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'last_page'], 'links']);

        $this->postJson("/api/v1/accounts/{$id}/status", ['status' => 'suspended'], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }

    public function test_duplicate_keycloak_sub_returns_409(): void
    {
        $this->postJson('/api/v1/accounts', [
            'keycloak_sub' => 'kc-new-1',
            'display_name' => 'A',
        ], $this->auth(['Idempotency-Key' => 'k-1-abcdefgh']))->assertCreated();

        $this->postJson('/api/v1/accounts', [
            'keycloak_sub' => 'kc-new-1',
            'display_name' => 'B',
        ], $this->auth(['Idempotency-Key' => 'k-2-abcdefgh']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ACCOUNT_DUPLICATE');
    }

    public function test_idempotency_replays_same_response(): void
    {
        $payload = ['keycloak_sub' => 'kc-idem-1', 'display_name' => 'Idem'];
        $headers = $this->auth(['Idempotency-Key' => 'idem-key-0001']);

        $first = $this->postJson('/api/v1/accounts', $payload, $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/v1/accounts', $payload, $headers);
        $second->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, IdempotencyKey::count());
    }

    public function test_role_assignment_flow(): void
    {
        $target = FunctionalAccount::create([
            'keycloak_sub' => 'kc-target', 'display_name' => 'Target', 'status' => 'active',
        ]);
        $role = Role::where('code', 'gestionnaire_patrimoine')->firstOrFail();

        $assign = $this->postJson("/api/v1/accounts/{$target->id}/roles", [
            'role_id' => $role->id,
            'institution_id' => (string) Str::uuid(),
        ], $this->auth(['Idempotency-Key' => 'role-assign-1']));

        $assign->assertCreated()->assertJsonPath('data.role_id', $role->id);
        $assignmentId = $assign->json('data.id');

        $this->deleteJson("/api/v1/accounts/{$target->id}/roles/{$assignmentId}", [], $this->auth())
            ->assertNoContent();
    }

    public function test_me_permissions_returns_effective_permissions(): void
    {
        $response = $this->getJson('/api/v1/me/permissions', $this->auth());
        $response->assertOk()
            ->assertJsonPath('account_id', $this->admin->id);
        $this->assertContains('identity.accounts.manage', $response->json('permissions'));
    }

    public function test_roles_and_permissions_endpoints(): void
    {
        $this->getJson('/api/v1/roles', $this->auth())
            ->assertOk()
            ->assertJsonPath('meta.total', 11);

        $role = Role::where('code', 'admin_principal')->firstOrFail();
        $this->getJson("/api/v1/roles/{$role->id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.code', 'admin_principal')
            ->assertJsonStructure(['data' => ['permissions']]);

        $this->getJson('/api/v1/permissions?domain=identity', $this->auth())
            ->assertOk()
            ->assertJsonPath('meta.total', 5);
    }

    public function test_validation_error_is_problem_json(): void
    {
        $this->postJson('/api/v1/accounts', ['display_name' => ''], $this->auth())
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors']);
    }

    public function test_delegation_requires_held_role(): void
    {
        $delegator = FunctionalAccount::create([
            'keycloak_sub' => 'kc-del', 'display_name' => 'Del', 'status' => 'active',
        ]);
        $beneficiary = FunctionalAccount::create([
            'keycloak_sub' => 'kc-ben', 'display_name' => 'Ben', 'status' => 'active',
        ]);
        $role = Role::where('code', 'gestionnaire_patrimoine')->firstOrFail();

        // Le délégant admin n'a pas le rôle gestionnaire → 409
        $this->postJson('/api/v1/delegations', [
            'beneficiary_functional_account_id' => $beneficiary->id,
            'role_id' => $role->id,
            'valid_from' => now()->toIso8601String(),
            'valid_to' => now()->addDay()->toIso8601String(),
        ], $this->auth(['Idempotency-Key' => 'del-test-0001']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'DELEGATION_ROLE_NOT_HELD');
    }
}
