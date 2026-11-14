<?php

namespace Tests\Feature;

use App\Models\AssetProjection;
use App\Models\IngestedEvent;
use App\Models\OutboxMessage;
use App\Models\ReportRun;
use App\Services\ReportingEventHandler;
use App\Services\TokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportingApiTest extends TestCase
{
    use RefreshDatabase;

    private string $sub;

    private string $institutionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sub = (string) Str::uuid7();
        $this->institutionId = (string) Str::uuid7();
    }

    private function auth(array $claims = []): array
    {
        $merged = array_merge([
            'sub' => $this->sub,
            'permissions' => ['reporting.read', 'reporting.manage'],
            'institution_id' => $this->institutionId,
        ], $claims);

        $this->app->instance(TokenValidator::class, new class($merged) implements TokenValidator
        {
            public function __construct(private array $claims) {}

            public function validate(string $token): array
            {
                return $this->claims;
            }
        });

        return ['Authorization' => 'Bearer fake-token'];
    }

    private function nationalAuth(): array
    {
        return $this->auth(['institution_id' => null]);
    }

    private function envelope(string $eventType, array $data, ?string $eventId = null, ?string $institutionId = null): array
    {
        $assetId = $data['asset_id'] ?? null;

        return [
            'event_id' => $eventId ?? (string) Str::uuid7(),
            'event_type' => $eventType,
            'event_version' => 1,
            'producer' => 'asset-service',
            'aggregate_type' => $assetId !== null ? 'asset' : 'test',
            'aggregate_id' => $assetId ?? (string) Str::uuid7(),
            'institution_id' => $institutionId ?? $this->institutionId,
            'occurred_at' => now()->toIso8601String(),
            'correlation_id' => (string) Str::uuid7(),
            'data' => $data,
        ];
    }

    private function registerAsset(string $assetId, string $institutionId, string $code = 'AST-001'): void
    {
        $this->app->make(ReportingEventHandler::class)->handle(
            $this->envelope('asset.registered.v1', [
                'asset_id' => $assetId,
                'institution_id' => $institutionId,
                'patrimonial_code' => $code,
                'designation' => 'Vehicule de liaison',
                'category_id' => (string) Str::uuid7(),
                'entry_mode' => 'purchase',
                'value_unknown' => true,
            ], null, $institutionId)
        );
    }

    // ─── Ingestion / projections ───────────────────────────────────────

    public function test_evenement_ingere_projection_creee_et_doublon_ignore(): void
    {
        $assetId = (string) Str::uuid7();
        $eventId = (string) Str::uuid7();
        $handler = $this->app->make(ReportingEventHandler::class);
        $env = $this->envelope('asset.registered.v1', [
            'asset_id' => $assetId,
            'patrimonial_code' => 'AST-100',
            'designation' => 'Ordinateur portable',
            'value_unknown' => true,
        ], $eventId);

        $this->assertSame('ingested', $handler->handle($env));
        $this->assertSame('duplicate', $handler->handle($env));

        $projection = AssetProjection::findOrFail($assetId);
        $this->assertSame('AST-100', $projection->patrimonial_code);
        $this->assertSame('active', $projection->state);
        $this->assertFalse($projection->value_known);
        $this->assertSame(1, IngestedEvent::count());
    }

    public function test_etat_projection_suit_les_evenements_metier(): void
    {
        $assetId = (string) Str::uuid7();
        $this->registerAsset($assetId, $this->institutionId);
        $handler = $this->app->make(ReportingEventHandler::class);

        $handler->handle($this->envelope('maintenance.intervention.started.v1', [
            'asset_id' => $assetId, 'intervention_id' => (string) Str::uuid7(),
        ]));
        $this->assertSame('under_maintenance', AssetProjection::find($assetId)->state);

        $handler->handle($this->envelope('finance.disposal_executed.v1', [
            'asset_id' => $assetId, 'disposal_id' => (string) Str::uuid7(),
        ]));
        $this->assertSame('disposed', AssetProjection::find($assetId)->state);
    }

    // ─── Dashboard / indicateurs / recherche ──────────────────────────

    public function test_dashboard_ministeriel_cloisonne(): void
    {
        $mine = (string) Str::uuid7();
        $foreign = (string) Str::uuid7();
        $this->registerAsset($mine, $this->institutionId, 'AST-M1');
        $this->registerAsset($foreign, $foreign, 'AST-F1');
        $this->app->make(ReportingEventHandler::class)->handle(
            $this->envelope('movement.internal_transfer.completed.v1', [
                'asset_id' => $mine, 'operation_id' => (string) Str::uuid7(),
            ])
        );

        $dashboard = $this->getJson('/api/v1/dashboard', $this->auth())->assertOk();
        $this->assertSame('institutional', $dashboard->json('data.scope'));
        $this->assertSame(1, $dashboard->json('data.assets_total'));
        $this->assertSame(1, $dashboard->json('data.movements_count'));

        // national voit les deux
        $national = $this->getJson('/api/v1/dashboard', $this->nationalAuth())->assertOk();
        $this->assertSame('national', $national->json('data.scope'));
        $this->assertSame(2, $national->json('data.assets_total'));
    }

    public function test_recherche_multicritere_et_chronologie(): void
    {
        $assetId = (string) Str::uuid7();
        $this->registerAsset($assetId, $this->institutionId, 'AST-777');

        $search = $this->getJson('/api/v1/assets?q=AST-777', $this->auth())->assertOk();
        $this->assertSame(1, $search->json('meta.total'));
        $this->assertSame('AST-777', $search->json('data.0.patrimonial_code'));

        // recherche par designation
        $this->getJson('/api/v1/assets?q=vehicule', $this->auth())->assertOk()
            ->assertJsonCount(1, 'data');

        // fiche + chronologie
        $detail = $this->getJson("/api/v1/assets/{$assetId}", $this->auth())->assertOk();
        $this->assertSame('AST-777', $detail->json('data.projection.patrimonial_code'));
        $this->assertNotEmpty($detail->json('data.chronology'));

        // etranger => 403
        $this->registerAsset((string) Str::uuid7(), (string) Str::uuid7(), 'AST-X');
        $foreignAsset = AssetProjection::where('patrimonial_code', 'AST-X')->first();
        $this->getJson("/api/v1/assets/{$foreignAsset->asset_id}", $this->auth())->assertForbidden();
    }

    public function test_indicateurs_retournent_les_courbes(): void
    {
        $this->registerAsset((string) Str::uuid7(), $this->institutionId);

        $indicators = $this->getJson('/api/v1/indicators', $this->auth())->assertOk();
        $codes = collect($indicators->json('data'))->pluck('code')->all();
        $this->assertContains('assets.total', $codes);
        $this->assertContains('movements.total', $codes);
    }

    // ─── Vues enregistrees ─────────────────────────────────────────────

    public function test_vues_enregistrees_par_profil_et_proprietaire(): void
    {
        $headers = $this->auth();
        $this->postJson('/api/v1/views', [
            'name' => 'Parc véhicules',
            'profile' => 'gestionnaire',
            'filters' => ['state' => 'active', 'q' => 'vehicule'],
        ], $headers)->assertCreated();

        $this->getJson('/api/v1/views', $headers)->assertOk()->assertJsonCount(1, 'data');

        // un autre compte ne voit pas la vue
        $this->getJson('/api/v1/views', $this->auth(['sub' => (string) Str::uuid7()]))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // ─── Rapports ──────────────────────────────────────────────────────

    public function test_modeles_nationaux_et_execution_rapport(): void
    {
        $assetId = (string) Str::uuid7();
        $this->registerAsset($assetId, $this->institutionId, 'AST-R1');

        $templates = $this->getJson('/api/v1/reports/templates', $this->auth())->assertOk();
        $this->assertGreaterThanOrEqual(4, count($templates->json('data')));

        $run = $this->postJson('/api/v1/reports/parc-actifs/run', [
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ], $this->auth(['institution_id' => null]))->assertCreated();

        $this->assertSame('completed', $run->json('data.status'));
        $this->assertStringStartsWith('RPT-', $run->json('data.reference'));
        $this->assertSame(1, $run->json('data.result_summary.row_count'));

        $id = $run->json('data.id');
        $detail = $this->getJson("/api/v1/report-runs/{$id}", $this->nationalAuth())->assertOk();
        $this->assertSame(1, $detail->json('data.result.row_count'));
        $this->assertSame('AST-R1', $detail->json('data.result.rows.0.patrimonial_code'));

        // evenement emis
        $this->assertTrue(OutboxMessage::where('event_type', 'reporting.report_generated')
            ->where('aggregate_id', $id)->exists());
    }

    public function test_rapport_ministeriel_borne_au_perimetre(): void
    {
        $foreign = (string) Str::uuid7();
        $this->registerAsset((string) Str::uuid7(), $this->institutionId, 'AST-MINE');
        $this->registerAsset((string) Str::uuid7(), $foreign, 'AST-FOREIGN');

        $run = $this->postJson('/api/v1/reports/parc-actifs/run', [
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ], $this->auth())->assertCreated();

        $detail = $this->getJson("/api/v1/report-runs/{$run->json('data.id')}", $this->auth())->assertOk();
        $codes = collect($detail->json('data.result.rows'))->pluck('patrimonial_code')->all();
        $this->assertSame(['AST-MINE'], $codes);
    }

    public function test_perimetre_etranger_refuse_et_modele_inconnu_404(): void
    {
        $foreignInstitution = (string) Str::uuid7();
        $this->postJson('/api/v1/reports/parc-actifs/run', [
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'institution_id' => $foreignInstitution,
        ], $this->auth())->assertForbidden();

        $this->postJson('/api/v1/reports/inconnu/run', [
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ], $this->auth())->assertNotFound();
    }

    public function test_export_csv_et_json(): void
    {
        $this->registerAsset((string) Str::uuid7(), $this->institutionId, 'AST-E1');

        $run = $this->postJson('/api/v1/reports/parc-actifs/run', [
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ], $this->auth())->assertCreated();

        $id = $run->json('data.id');
        $csv = $this->get("/api/v1/report-runs/{$id}/export?format=csv", $this->auth());
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));
        $this->assertStringContainsString('AST-E1', $csv->streamedContent());

        $this->getJson("/api/v1/report-runs/{$id}/export?format=json", $this->auth())
            ->assertOk()->assertJsonPath('data.row_count', 1);
    }

    public function test_rapport_non_termine_export_refuse(): void
    {
        $run = ReportRun::create([
            'reference' => 'RPT-TEST-1',
            'template_code' => 'parc-actifs',
            'institution_id' => $this->institutionId,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'status' => 'pending',
            'confidentiality' => 'internal',
            'generated_by' => $this->sub,
        ]);

        $this->getJson("/api/v1/report-runs/{$run->id}/export", $this->auth())
            ->assertStatus(409)->assertJsonPath('code', 'REPORT_NOT_READY');
    }

    public function test_sans_permission_403_et_sans_token_401(): void
    {
        $this->getJson('/api/v1/dashboard', $this->auth(['permissions' => ['asset.read']]))->assertForbidden();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }
}
