<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\Institution;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitType;
use App\Models\Position;
use App\Services\TokenValidator;
use Database\Seeders\UnitTypesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    private string $sub = 'kc-admin-sub';
    private array $permissions = [
        'organization.institutions.read',
        'organization.institutions.manage',
        'organization.units.read',
        'organization.units.manage',
    ];
    private string $token = 'fake.jwt.token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UnitTypesTableSeeder::class);

        $perms = $this->permissions;
        $sub = $this->sub;
        $this->app->instance(TokenValidator::class, new class($sub, $perms) implements TokenValidator {
            public function __construct(private string $sub, private array $perms) {}
            public function validate(string $token): array
            {
                return ['sub' => $this->sub, 'permissions' => $this->perms, 'institution_id' => null];
            }
        });
    }

    private function auth(array $headers = []): array
    {
        return ['Authorization' => 'Bearer ' . $this->token] + $headers;
    }

    private function makeInstitution(): Institution
    {
        return Institution::create([
            'code' => 'MIN-' . Str::upper(Str::random(6)),
            'name' => 'Ministère Test',
            'type' => 'ministere',
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('service', 'organization-service');
    }

    public function test_endpoints_require_token(): void
    {
        $this->getJson('/api/v1/institutions')->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_permission_required(): void
    {
        // Token sans permission institutions.read
        $this->app->instance(TokenValidator::class, new class implements TokenValidator {
            public function validate(string $token): array
            {
                return ['sub' => 'x', 'permissions' => [], 'institution_id' => null];
            }
        });

        $this->getJson('/api/v1/institutions', $this->auth())->assertStatus(403);
    }

    public function test_institution_crud_and_status(): void
    {
        $create = $this->postJson('/api/v1/institutions', [
            'code' => 'MIN-FIN',
            'name' => 'Ministère des Finances',
            'type' => 'ministere',
        ], $this->auth(['Idempotency-Key' => 'inst-key-0001']));

        $create->assertCreated()
            ->assertJsonPath('data.code', 'MIN-FIN')
            ->assertJsonPath('data.status', 'active');

        $id = $create->json('data.id');
        $this->getJson("/api/v1/institutions/{$id}", $this->auth())
            ->assertOk()->assertJsonPath('data.name', 'Ministère des Finances');

        $this->postJson("/api/v1/institutions/{$id}/status", ['status' => 'suspended'], $this->auth())
            ->assertOk()->assertJsonPath('data.status', 'suspended');
    }

    public function test_unit_tree_flow(): void
    {
        $institution = $this->makeInstitution();
        $type = OrganizationalUnitType::where('code', 'direction')->firstOrFail();

        $dir = $this->postJson("/api/v1/institutions/{$institution->id}/units", [
            'unit_type_id' => $type->id,
            'code' => 'DAF',
            'name' => 'Direction des Affaires Financières',
        ], $this->auth(['Idempotency-Key' => 'unit-key-001']));
        $dir->assertCreated()->assertJsonPath('data.code', 'DAF');
        $dirId = $dir->json('data.id');

        $svcType = OrganizationalUnitType::where('code', 'service')->firstOrFail();
        $svc = $this->postJson("/api/v1/institutions/{$institution->id}/units", [
            'unit_type_id' => $svcType->id,
            'parent_id' => $dirId,
            'code' => 'SRV-COMPTA',
            'name' => 'Service Comptabilité',
        ], $this->auth(['Idempotency-Key' => 'unit-key-002']));
        $svc->assertCreated()->assertJsonPath('data.parent_id', $dirId);
        $svcId = $svc->json('data.id');

        // Cycle : déplacer DAF sous SRV-COMPTA doit être refusé
        $this->postJson("/api/v1/units/{$dirId}/move", ['parent_id' => $svcId], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('code', 'UNIT_MOVE_CYCLE');

        $this->patchJson("/api/v1/units/{$svcId}", ['name' => 'Service Comptabilité Générale'], $this->auth())
            ->assertOk()->assertJsonPath('data.name', 'Service Comptabilité Générale');
    }

    public function test_positions_and_head(): void
    {
        $institution = $this->makeInstitution();
        $type = OrganizationalUnitType::where('code', 'direction')->firstOrFail();

        $unit = OrganizationalUnit::create([
            'institution_id' => $institution->id,
            'unit_type_id' => $type->id,
            'code' => 'DG',
            'name' => 'Direction Générale',
            'status' => 'active',
        ]);

        $pos = $this->postJson("/api/v1/units/{$unit->id}/positions", [
            'code' => 'DG-CHEF',
            'title' => 'Directeur Général',
        ], $this->auth(['Idempotency-Key' => 'pos-key-0001']));
        $pos->assertCreated();
        $posId = $pos->json('data.id');

        $accountId = (string) Str::uuid7();
        $this->postJson("/api/v1/positions/{$posId}/responsible", [
            'functional_account_id' => $accountId,
        ], $this->auth(['Idempotency-Key' => 'resp-key-0001']))
            ->assertOk()->assertJsonPath('data.functional_account_id', $accountId);

        $this->postJson("/api/v1/units/{$unit->id}/head", ['position_id' => $posId], $this->auth())
            ->assertOk()->assertJsonPath('data.head_position_id', $posId);
    }

    public function test_histories_endpoint(): void
    {
        $institution = $this->makeInstitution();

        $this->getJson("/api/v1/institutions/{$institution->id}/histories", $this->auth())
            ->assertOk()->assertJsonStructure(['data', 'meta']);
    }

    public function test_idempotency_replay(): void
    {
        $payload = ['code' => 'MIN-IDEM', 'name' => 'Idem', 'type' => 'ministere'];
        $headers = $this->auth(['Idempotency-Key' => 'idem-org-0001']);

        $first = $this->postJson('/api/v1/institutions', $payload, $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/v1/institutions', $payload, $headers);
        $second->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, IdempotencyKey::count());
    }

    public function test_validation_error_is_problem_json(): void
    {
        $this->postJson('/api/v1/institutions', ['name' => ''], $this->auth())
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }
}
