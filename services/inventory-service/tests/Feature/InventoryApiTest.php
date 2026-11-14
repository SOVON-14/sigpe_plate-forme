<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\InventoryCampaign;
use App\Models\InventoryFinding;
use App\Services\TokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private string $institutionId;

    private string $otherInstitutionId;

    private string $token = 'fake.jwt.token';

    private string $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institutionId = (string) Str::uuid7();
        $this->otherInstitutionId = (string) Str::uuid7();
        $this->accountId = (string) Str::uuid7();

        $institutionId = $this->institutionId;
        $this->app->instance(TokenValidator::class, new class($institutionId) implements TokenValidator
        {
            public function __construct(private string $institutionId) {}

            public function validate(string $token): array
            {
                return [
                    'sub' => 'kc-agent-inv-1',
                    'permissions' => [
                        'inventory.campaigns.read',
                        'inventory.campaigns.manage',
                        'inventory.agents.manage',
                        'inventory.findings.record',
                        'inventory.findings.verify',
                        'inventory.reports.read',
                    ],
                    'institution_id' => $this->institutionId,
                ];
            }
        });
    }

    private function auth(array $headers = []): array
    {
        return ['Authorization' => 'Bearer '.$this->token] + $headers;
    }

    private function campaignPayload(array $overrides = []): array
    {
        return array_merge([
            'designation' => 'Inventaire general annuel 2026',
            'type' => 'general_annual',
            'period_start' => '2026-01-05',
            'period_end' => '2026-02-28',
            'responsible_account_id' => (string) Str::uuid7(),
        ], $overrides);
    }

    private function createCampaign(array $overrides = []): InventoryCampaign
    {
        $response = $this->postJson(
            "/api/v1/institutions/{$this->institutionId}/inventory-campaigns",
            $this->campaignPayload($overrides),
            $this->auth(['Idempotency-Key' => 'camp-'.Str::random(8)]),
        );
        $response->assertCreated();

        return InventoryCampaign::findOrFail($response->json('data.id'));
    }

    private function startCampaign(): InventoryCampaign
    {
        $campaign = $this->createCampaign();
        $this->postJson("/api/v1/inventory-campaigns/{$campaign->id}/status", [
            'status' => 'in_progress',
        ], $this->auth())->assertOk();

        return $campaign->fresh();
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('service', 'inventory-service');
    }

    public function test_endpoints_require_token(): void
    {
        $this->getJson("/api/v1/institutions/{$this->institutionId}/inventory-campaigns")
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_create_and_list_campaigns(): void
    {
        $campaign = $this->createCampaign();

        $this->assertSame('draft', $campaign->status->value);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $campaign->id);

        $this->getJson("/api/v1/institutions/{$this->institutionId}/inventory-campaigns", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.id', $campaign->id)
            ->assertJsonPath('data.0.counters.recorded', 0)
            ->assertJsonStructure(['meta' => ['total'], 'links']);

        $this->getJson("/api/v1/inventory-campaigns/{$campaign->id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.designation', 'Inventaire general annuel 2026');
    }

    public function test_foreign_institution_scope_is_forbidden(): void
    {
        $this->getJson("/api/v1/institutions/{$this->otherInstitutionId}/inventory-campaigns", $this->auth())
            ->assertStatus(403);
    }

    public function test_invalid_period_validation(): void
    {
        $this->postJson(
            "/api/v1/institutions/{$this->institutionId}/inventory-campaigns",
            $this->campaignPayload(['period_start' => '2026-03-01', 'period_end' => '2026-02-01']),
            $this->auth(),
        )->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_full_campaign_lifecycle_with_findings(): void
    {
        $campaign = $this->startCampaign();
        $assetId = (string) Str::uuid7();

        // Constat avec anomalie
        $finding = $this->postJson(
            "/api/v1/inventory-campaigns/{$campaign->id}/findings",
            [
                'asset_id' => $assetId,
                'result' => 'trouve',
                'anomaly_type' => 'endommage',
                'identification_method' => 'qr_code',
                'location_found' => 'Bureau direction',
            ],
            $this->auth(['Idempotency-Key' => 'find-'.Str::random(8)]),
        );
        $finding->assertCreated()
            ->assertJsonPath('data.result', 'trouve')
            ->assertJsonPath('data.verification_status', 'pending');

        // Constat bien non enregistre (sans asset_id → free_reference requise)
        $this->postJson(
            "/api/v1/inventory-campaigns/{$campaign->id}/findings",
            ['result' => 'trouve', 'identification_method' => 'manual'],
            $this->auth(),
        )->assertStatus(422);

        $this->postJson(
            "/api/v1/inventory-campaigns/{$campaign->id}/findings",
            [
                'free_reference' => 'BIEN-INCONNU-42',
                'designation_found' => 'Armoire metallique',
                'result' => 'trouve',
                'anomaly_type' => 'non_enregistre',
                'identification_method' => 'manual',
            ],
            $this->auth(),
        )->assertCreated();

        // Constat non trouve
        $this->postJson(
            "/api/v1/inventory-campaigns/{$campaign->id}/findings",
            [
                'asset_id' => (string) Str::uuid7(),
                'result' => 'non_trouve',
                'identification_method' => 'qr_code',
            ],
            $this->auth(),
        )->assertCreated()->assertJsonPath('data.anomaly_type', null);

        // Liste des constats
        $this->getJson("/api/v1/inventory-campaigns/{$campaign->id}/findings", $this->auth())
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        // Cloture bloquee : constats en attente
        $this->postJson("/api/v1/inventory-campaigns/{$campaign->id}/status", ['status' => 'under_review'], $this->auth())
            ->assertOk();
        $this->postJson("/api/v1/inventory-campaigns/{$campaign->id}/status", ['status' => 'closed'], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('code', 'CAMPAIGN_PENDING_FINDINGS');

        // Verification des constats
        $pending = InventoryFinding::where('campaign_id', $campaign->id)
            ->where('verification_status', 'pending')->get();
        foreach ($pending as $f) {
            $this->postJson("/api/v1/findings/{$f->id}/verify", [
                'decision' => 'confirmed',
                'notes' => 'Rapprochement effectue',
            ], $this->auth())->assertOk()
                ->assertJsonPath('data.verification_status', 'confirmed');
        }

        // Double verification → 409
        $this->postJson("/api/v1/findings/{$pending->first()->id}/verify", [
            'decision' => 'rejected',
        ], $this->auth())->assertStatus(409);

        // Cloture OK + rapport
        $this->postJson("/api/v1/inventory-campaigns/{$campaign->id}/status", ['status' => 'closed'], $this->auth())
            ->assertOk()->assertJsonPath('data.status', 'closed');

        $this->getJson("/api/v1/inventory-campaigns/{$campaign->id}/report", $this->auth())
            ->assertOk()
            ->assertJsonPath('totals.recorded', 3)
            ->assertJsonPath('totals.anomalies', 2)
            ->assertJsonPath('pending_verification', []);
    }

    public function test_agents_assignment_flow(): void
    {
        $campaign = $this->createCampaign();
        $agentAccount = (string) Str::uuid7();

        $assign = $this->postJson(
            "/api/v1/inventory-campaigns/{$campaign->id}/agents",
            ['functional_account_id' => $agentAccount],
            $this->auth(['Idempotency-Key' => 'agent-'.Str::random(8)]),
        );
        $assign->assertCreated()
            ->assertJsonPath('data.functional_account_id', $agentAccount);

        $agentId = $assign->json('data.id');

        // Double affectation → 409
        $this->postJson(
            "/api/v1/inventory-campaigns/{$campaign->id}/agents",
            ['functional_account_id' => $agentAccount],
            $this->auth(),
        )->assertStatus(409);

        $this->getJson("/api/v1/inventory-campaigns/{$campaign->id}/agents", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.id', $agentId);

        $this->deleteJson(
            "/api/v1/inventory-campaigns/{$campaign->id}/agents/{$agentId}",
            ['motif' => 'Fin de mission'],
            $this->auth(),
        )->assertNoContent();
    }

    public function test_idempotency_replay_on_campaign_create(): void
    {
        $payload = $this->campaignPayload();
        $headers = $this->auth(['Idempotency-Key' => 'idem-camp-0001']);
        $url = "/api/v1/institutions/{$this->institutionId}/inventory-campaigns";

        $first = $this->postJson($url, $payload, $headers);
        $first->assertCreated();

        $second = $this->postJson($url, $payload, $headers);
        $second->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, IdempotencyKey::count());
    }

    public function test_findings_not_recordable_on_draft_campaign(): void
    {
        $campaign = $this->createCampaign();

        $this->postJson(
            "/api/v1/inventory-campaigns/{$campaign->id}/findings",
            [
                'asset_id' => (string) Str::uuid7(),
                'result' => 'trouve',
                'identification_method' => 'qr_code',
            ],
            $this->auth(),
        )->assertStatus(422)
            ->assertJsonPath('code', 'FINDING_CAMPAIGN_NOT_OPEN');
    }
}
