<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\IdempotencyKey;
use App\Services\TokenValidator;
use Database\Seeders\AssetCategoriesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    private string $institutionId;
    private string $otherInstitutionId;
    private string $token = 'fake.jwt.token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AssetCategoriesTableSeeder::class);
        $this->institutionId = (string) Str::uuid7();
        $this->otherInstitutionId = (string) Str::uuid7();

        $institutionId = $this->institutionId;
        $this->app->instance(TokenValidator::class, new class($institutionId) implements TokenValidator {
            public function __construct(private string $institutionId) {}
            public function validate(string $token): array
            {
                return [
                    'sub' => 'kc-agent-1',
                    'permissions' => ['asset.read', 'asset.manage', 'asset.rattachement.confirm'],
                    'institution_id' => $this->institutionId,
                ];
            }
        });
    }

    private function auth(array $headers = []): array
    {
        return ['Authorization' => 'Bearer ' . $this->token] + $headers;
    }

    private function payload(array $overrides = []): array
    {
        $category = AssetCategory::where('code', 'materiels_roulants')->firstOrFail();

        return array_merge([
            'category_id' => $category->id,
            'designation' => 'Véhicule de service',
            'entry_date' => '2026-01-15',
            'entry_mode' => 'achat',
            'physical_state' => 'neuf',
        ], $overrides);
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('service', 'asset-service');
    }

    public function test_endpoints_require_token(): void
    {
        $this->getJson("/api/v1/institutions/{$this->institutionId}/assets")
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_register_and_retrieve_asset(): void
    {
        $create = $this->postJson(
            "/api/v1/institutions/{$this->institutionId}/assets",
            $this->payload(),
            $this->auth(['Idempotency-Key' => 'asset-key-001']),
        );

        $create->assertCreated()
            ->assertJsonPath('data.patrimonial_code', fn ($v) => str_starts_with($v, 'PAT-'))
            ->assertJsonPath('data.administrative_status', 'actif')
            ->assertJsonStructure(['data' => ['id', 'qr_token']]);

        $id = $create->json('data.id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);

        $this->getJson("/api/v1/assets/{$id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.designation', 'Véhicule de service');
    }

    public function test_foreign_institution_scope_is_forbidden(): void
    {
        // Le jeton est scopé sur institutionId ; viser une autre institution → 403
        $this->getJson("/api/v1/institutions/{$this->otherInstitutionId}/assets", $this->auth())
            ->assertStatus(403);
    }

    public function test_value_unknown_requires_justification(): void
    {
        $this->postJson(
            "/api/v1/institutions/{$this->institutionId}/assets",
            $this->payload(['value_unknown' => true]),
            $this->auth(),
        )->assertStatus(422);
    }

    public function test_status_flow_enforces_reform_before_exit(): void
    {
        $asset = $this->registerAsset();
        $id = $asset->id;

        $this->postJson("/api/v1/assets/{$id}/status", ['status' => 'sorti'], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('code', 'ASSET_EXIT_REQUIRES_REFORM');

        $this->postJson("/api/v1/assets/{$id}/status", ['status' => 'en_reforme'], $this->auth())
            ->assertOk()->assertJsonPath('data.administrative_status', 'en_reforme');

        $this->postJson("/api/v1/assets/{$id}/status", ['status' => 'sorti'], $this->auth())
            ->assertOk()->assertJsonPath('data.administrative_status', 'sorti');
    }

    public function test_rattachement_confirm_assigns_new_code(): void
    {
        $asset = $this->registerAsset();
        $oldCode = $asset->patrimonial_code;

        $response = $this->postJson("/api/v1/assets/{$asset->id}/rattachement", [
            'institution_id' => $this->otherInstitutionId,
        ], $this->auth(['Idempotency-Key' => 'ratt-key-0001']));

        // Le jeton est scopé sur l'ancienne institution → le middleware
        // ABAC refuse ? Non : route asset → institution_id de l'asset
        // (ancienne) correspond au claim → autorisé.
        $response->assertOk()
            ->assertJsonPath('data.id', $asset->id)
            ->assertJsonPath('data.institution_id', $this->otherInstitutionId);
        $this->assertNotSame($oldCode, $response->json('data.patrimonial_code'));
    }

    public function test_idempotency_replay(): void
    {
        $payload = $this->payload();
        $headers = $this->auth(['Idempotency-Key' => 'idem-asset-1']);
        $url = "/api/v1/institutions/{$this->institutionId}/assets";

        $first = $this->postJson($url, $payload, $headers);
        $first->assertCreated();

        $second = $this->postJson($url, $payload, $headers);
        $second->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, IdempotencyKey::count());
    }

    public function test_categories_and_histories(): void
    {
        $this->getJson('/api/v1/categories', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.code', 'autres_biens'); // tri par code

        $category = AssetCategory::firstOrFail();
        $this->getJson("/api/v1/categories/{$category->id}/subcategories", $this->auth())
            ->assertOk()->assertJsonStructure(['data']);

        $asset = $this->registerAsset();
        $this->getJson("/api/v1/assets/{$asset->id}/histories", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.change_type', 'registered');
    }

    private function registerAsset(): Asset
    {
        $response = $this->postJson(
            "/api/v1/institutions/{$this->institutionId}/assets",
            $this->payload(),
            $this->auth(),
        );
        $response->assertCreated();

        return Asset::findOrFail($response->json('data.id'));
    }
}
