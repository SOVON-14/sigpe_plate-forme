<?php

namespace Tests\Feature;

use App\Enums\DisposalStatus;
use App\Exceptions\DomainException;
use App\Models\AssetCost;
use App\Models\AssetValuation;
use App\Models\Disposal;
use App\Models\DisposalHistory;
use App\Models\OutboxMessage;
use App\Services\FinanceEventHandler;
use App\Services\FinanceService;
use App\Services\TokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceApiTest extends TestCase
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
            'permissions' => ['finance.read', 'finance.manage'],
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

    private function assetId(): string
    {
        return (string) Str::uuid7();
    }

    private function determinateValuation(string $assetId, float $amount = 12_000_000): AssetValuation
    {
        return $this->app->make(FinanceService::class)->createValuation([
            'asset_id' => $assetId,
            'valuation_type' => 'acquisition',
            'valuation_date' => '2026-01-15',
            'amount' => $amount,
            'depreciation_method' => 'linear',
            'useful_life_months' => 60,
        ], $this->institutionId, $this->sub);
    }

    // ─── Valorisations ────────────────────────────────────────────────

    public function test_valorisation_conservee_et_evenement_emis(): void
    {
        $assetId = $this->assetId();
        $response = $this->postJson('/api/v1/asset-valuations', [
            'asset_id' => $assetId,
            'valuation_type' => 'acquisition',
            'valuation_date' => '2026-02-01',
            'amount' => 8_500_000,
            'currency' => 'xof',
        ], $this->auth())->assertCreated();

        $id = $response->json('data.id');
        $this->assertSame('XOF', $response->json('data.currency'));
        $this->assertTrue($response->json('data.is_determinate'));

        $this->assertTrue(OutboxMessage::where('event_type', 'finance.valuation_recorded')
            ->where('aggregate_id', $id)->exists());
    }

    public function test_valorisation_indeterminee_amount_null(): void
    {
        $response = $this->postJson('/api/v1/asset-valuations', [
            'asset_id' => $this->assetId(),
            'valuation_type' => 'acquisition',
            'valuation_date' => '2026-02-01',
            'amount' => null,
        ], $this->auth())->assertCreated();

        $this->assertFalse($response->json('data.is_determinate'));
        $this->assertNull($response->json('data.amount'));
    }

    public function test_vue_valorisation_calcule_vnc_et_couts(): void
    {
        $assetId = $this->assetId();
        $this->determinateValuation($assetId, 12_000_000);
        $this->app->make(FinanceService::class)->recordDepreciation($assetId, [
            'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        ], $this->institutionId, $this->sub);
        $this->app->make(FinanceService::class)->createCost([
            'asset_id' => $assetId, 'category' => 'repair', 'amount' => 500_000,
            'incurred_at' => '2026-01-20', 'description' => 'Reparation pompe',
        ], $this->institutionId, $this->sub);

        $view = $this->getJson("/api/v1/assets/{$assetId}/valuation", $this->auth())->assertOk();

        // lineaire : 12M / 60 mois = 200k/mois ; janvier = 200k
        $this->assertSame(12_000_000.0, (float) $view->json('data.acquisition_value'));
        $this->assertEqualsWithDelta(200_000.0, (float) $view->json('data.accumulated_depreciation'), 0.01);
        $this->assertEqualsWithDelta(11_800_000.0, (float) $view->json('data.net_book_value'), 0.01);
        $this->assertSame(500_000.0, (float) $view->json('data.total_costs.XOF'));
        $this->assertTrue($view->json('data.is_fully_determinate'));
    }

    // ─── Amortissements ───────────────────────────────────────────────

    public function test_amortissement_lineaire_calcule_et_uniqueness_periode(): void
    {
        $assetId = $this->assetId();
        $this->determinateValuation($assetId, 12_000_000);
        $service = $this->app->make(FinanceService::class);

        $entry = $service->recordDepreciation($assetId, [
            'period_start' => '2026-01-01', 'period_end' => '2026-03-31',
        ], $this->institutionId, $this->sub);

        // 3 mois * 200k = 600k
        $this->assertEqualsWithDelta(600_000.0, (float) $entry->amount, 0.01);
        $this->assertSame('linear', $entry->method);
        $this->assertTrue(OutboxMessage::where('event_type', 'finance.depreciation_recorded')
            ->where('aggregate_id', $entry->id)->exists());

        // meme periode => conflit
        $this->expectException(DomainException::class);
        $service->recordDepreciation($assetId, [
            'period_start' => '2026-01-01', 'period_end' => '2026-03-31',
        ], $this->institutionId, $this->sub);
    }

    public function test_amortissement_sans_base_determinate_refuse(): void
    {
        $this->app->make(FinanceService::class)->createValuation([
            'asset_id' => $assetId = $this->assetId(),
            'valuation_type' => 'acquisition',
            'valuation_date' => '2026-01-01',
            'amount' => null,
        ], $this->institutionId, $this->sub);

        $this->postJson("/api/v1/assets/{$assetId}/depreciation-entries", [
            'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        ], $this->auth())->assertStatus(422)
            ->assertJsonPath('code', 'VALUATION_NOT_DETERMINATE');
    }

    // ─── Couts ────────────────────────────────────────────────────────

    public function test_cout_manuels_et_synthese_par_categorie(): void
    {
        $assetId = $this->assetId();
        $this->postJson('/api/v1/asset-costs', [
            'asset_id' => $assetId,
            'category' => 'shared_cost_share',
            'amount' => 1_200_000,
            'incurred_at' => '2026-01-10',
            'description' => 'Part de cout bien partage',
            'beneficiary_account_id' => (string) Str::uuid7(),
        ], $this->auth())->assertCreated();

        $summary = $this->getJson("/api/v1/assets/{$assetId}/costs", $this->auth())->assertOk();
        $this->assertSame(1_200_000.0, (float) $summary->json('data.total_amount'));
        $this->assertSame(1_200_000.0, (float) $summary->json('data.by_category.shared_cost_share'));
        $this->assertSame(1, $summary->json('data.cost_count'));
    }

    public function test_evenement_intervention_cree_les_lignes_de_couts(): void
    {
        $assetId = $this->assetId();
        $interventionId = (string) Str::uuid7();
        $eventId = (string) Str::uuid7();

        $count = $this->app->make(FinanceEventHandler::class)->handle([
            'event_id' => $eventId,
            'event_type' => 'maintenance.intervention.completed.v1',
            'producer' => 'maintenance-service',
            'aggregate_id' => $interventionId,
            'institution_id' => $this->institutionId,
            'correlation_id' => (string) Str::uuid7(),
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'intervention_id' => $interventionId,
                'asset_id' => $assetId,
                'institution_id' => $this->institutionId,
                'costs' => [
                    'labor' => 300_000, 'parts' => 150_000, 'supplies' => 0,
                    'transport' => 50_000, 'external_services' => 0, 'other' => 25_000,
                    'total' => 525_000,
                ],
                'completed_at' => '2026-02-10T10:00:00Z',
            ],
        ]);

        $this->assertSame('costs:4', $count);
        $this->assertSame(4, AssetCost::where('source_id', $interventionId)->count());
        $this->assertSame(525_000.0, (float) AssetCost::where('source_id', $interventionId)->sum('amount'));

        // rejeu du meme evenement => deduplique par inbox
        $this->assertSame('duplicate', $this->app->make(FinanceEventHandler::class)->handle([
            'event_id' => $eventId,
            'event_type' => 'maintenance.intervention.completed.v1',
            'data' => ['intervention_id' => $interventionId, 'asset_id' => $assetId,
                'costs' => ['labor' => 300_000, 'parts' => 150_000, 'transport' => 50_000, 'other' => 25_000]],
        ]));
        $this->assertSame(4, AssetCost::where('source_id', $interventionId)->count());
    }

    // ─── Sorties definitives ──────────────────────────────────────────

    private function approvedDisposal(string $type = 'reform', ?float $value = 12_000_000): Disposal
    {
        $assetId = $this->assetId();
        if ($value !== null) {
            $this->determinateValuation($assetId, $value);
        }
        $service = $this->app->make(FinanceService::class);
        $data = [
            'asset_id' => $assetId,
            'disposal_type' => $type,
            'reason' => 'Reforme pour fin de vie utile du materiel',
        ];
        if (in_array($type, ['loss', 'theft'], true)) {
            $data['incident_reported_at'] = now()->toIso8601String();
        }
        $disposal = $service->createDisposal($data, $this->institutionId, $this->sub);
        if ($disposal->status === DisposalStatus::UnderInvestigation) {
            $service->resolveInvestigation($disposal, ['resolution' => $type === 'theft' ? 'theft_confirmed' : 'loss_confirmed'], $this->sub);
            $disposal->refresh();
        }
        $instanceId = (string) Str::uuid7();
        $service->submit($disposal->fresh(), $instanceId, $this->sub);
        $service->handleWorkflowOutcome($instanceId, 'approved', $this->sub);

        return $disposal->fresh();
    }

    public function test_cycle_complet_sortie_reform(): void
    {
        $disposal = $this->approvedDisposal('reform', 12_000_000);
        $this->assertSame(DisposalStatus::Approved, $disposal->status);
        // 12M >= seuil ministerial (50M? non : 12M < 50M => institutional)
        $this->assertSame('institutional', $disposal->required_competence_level->value);
        $this->assertNotNull($disposal->net_book_value);

        $this->assertTrue(OutboxMessage::where('event_type', 'finance.disposal_approved')
            ->where('aggregate_id', $disposal->id)->exists());

        $service = $this->app->make(FinanceService::class);
        $executed = $service->execute($disposal, $this->sub);
        $this->assertSame(DisposalStatus::Executed, $executed->status);
        $closed = $service->close($executed, $this->sub);
        $this->assertSame(DisposalStatus::Closed, $closed->status);

        $this->assertTrue(OutboxMessage::where('event_type', 'finance.disposal_executed')
            ->where('aggregate_id', $disposal->id)->exists());
        $this->assertTrue(OutboxMessage::where('event_type', 'finance.disposal_closed')
            ->where('aggregate_id', $disposal->id)->exists());

        // historique complet conserve
        $this->assertGreaterThanOrEqual(4, DisposalHistory::where('disposal_id', $disposal->id)->count());
    }

    public function test_perte_biens_reste_au_registre_pendant_investigation(): void
    {
        $service = $this->app->make(FinanceService::class);
        $assetId = $this->assetId();
        $disposal = $service->createDisposal([
            'asset_id' => $assetId,
            'disposal_type' => 'loss',
            'reason' => 'Bien declare perdu apres inventaire contradictoire',
            'incident_reported_at' => now()->toIso8601String(),
        ], $this->institutionId, $this->sub);

        // investigation immediate — pas de draft, pas de sortie
        $this->assertSame(DisposalStatus::UnderInvestigation, $disposal->status);

        // execution refusee pendant l'investigation
        try {
            $service->execute($disposal, $this->sub);
            $this->fail('Execution aurait du etre refusee');
        } catch (DomainException $e) {
            $this->assertSame('DISPOSAL_NOT_EXECUTABLE', $e->errorCode);
        }

        // bien retrouve => cloture sans sortie
        $resolved = $service->resolveInvestigation($disposal, ['resolution' => 'asset_found'], $this->sub);
        $this->assertSame(DisposalStatus::Closed, $resolved->status);
    }

    public function test_perte_confirmee_puis_decision(): void
    {
        $service = $this->app->make(FinanceService::class);
        $disposal = $service->createDisposal([
            'asset_id' => $this->assetId(),
            'disposal_type' => 'theft',
            'reason' => 'Vol constate avec proces-verbal des autorites',
            'incident_reported_at' => now()->toIso8601String(),
        ], $this->institutionId, $this->sub);

        $resolved = $service->resolveInvestigation($disposal, [
            'resolution' => 'theft_confirmed', 'comment' => 'PV verse au dossier',
        ], $this->sub);
        $this->assertSame(DisposalStatus::PendingValidation, $resolved->status);
    }

    public function test_competence_nationale_pour_forte_valeur(): void
    {
        $service = $this->app->make(FinanceService::class);
        $this->assertSame('institutional', $service->requiredCompetence(10_000_000));
        $this->assertSame('ministerial', $service->requiredCompetence(100_000_000));
        $this->assertSame('national', $service->requiredCompetence(600_000_000));
        $this->assertSame('ministerial', $service->requiredCompetence(null)); // indetermine => prudence
    }

    public function test_execute_sans_approbation_refuse_et_double_dossier_refuse(): void
    {
        $service = $this->app->make(FinanceService::class);
        $assetId = $this->assetId();
        $disposal = $service->createDisposal([
            'asset_id' => $assetId,
            'disposal_type' => 'sale',
            'reason' => 'Vente d\'un bien reforme par adjudication',
        ], $this->institutionId, $this->sub);

        try {
            $service->execute($disposal, $this->sub);
            $this->fail();
        } catch (DomainException $e) {
            $this->assertSame('DISPOSAL_NOT_EXECUTABLE', $e->errorCode);
        }

        try {
            $service->createDisposal([
                'asset_id' => $assetId, 'disposal_type' => 'donation',
                'reason' => 'Tentative de second dossier sur le meme bien',
            ], $this->institutionId, $this->sub);
            $this->fail();
        } catch (DomainException $e) {
            $this->assertSame('DISPOSAL_ALREADY_OPEN', $e->errorCode);
        }
    }

    // ─── API / securite ───────────────────────────────────────────────

    public function test_flux_api_disposal_complet(): void
    {
        $assetId = $this->assetId();
        $this->determinateValuation($assetId);

        $created = $this->postJson('/api/v1/disposals', [
            'asset_id' => $assetId,
            'disposal_type' => 'reform',
            'reason' => 'Reforme d\'un vehicule au-dela de sa duree de vie',
        ], $this->auth())->assertCreated();

        $id = $created->json('data.id');
        $this->assertSame('draft', $created->json('data.status'));
        $this->assertSame('institutional', $created->json('data.required_competence_level'));

        $instanceId = (string) Str::uuid7();
        $this->postJson("/api/v1/disposals/{$id}/submit", [
            'workflow_instance_id' => $instanceId,
        ], $this->auth())->assertOk()->assertJsonPath('data.status', 'pending_validation');

        $this->app->make(FinanceService::class)->handleWorkflowOutcome($instanceId, 'approved', $this->sub);
        $this->postJson("/api/v1/disposals/{$id}/execute", [], $this->auth())
            ->assertOk()->assertJsonPath('data.status', 'executed');
        $this->postJson("/api/v1/disposals/{$id}/close", [], $this->auth())
            ->assertOk()->assertJsonPath('data.status', 'closed');

        $this->getJson("/api/v1/disposals/{$id}/history", $this->auth())->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_abac_disposal_etranger_403_et_liste_cloisonnee(): void
    {
        $foreign = $this->app->make(FinanceService::class)->createDisposal([
            'asset_id' => $this->assetId(),
            'disposal_type' => 'reform',
            'reason' => 'Dossier cree par une autre institution ministerielle',
        ], (string) Str::uuid7(), (string) Str::uuid7());

        $this->getJson("/api/v1/disposals/{$foreign->id}", $this->auth())->assertForbidden();

        // la liste ne contient pas le dossier etranger
        $this->getJson('/api/v1/disposals', $this->auth())->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_sans_permission_403_et_sans_token_401(): void
    {
        $this->getJson('/api/v1/disposals', $this->auth(['permissions' => ['asset.read']]))->assertForbidden();
        $this->getJson('/api/v1/disposals')->assertUnauthorized();
    }

    public function test_evenement_asset_registered_cree_valorisation_indeterminee(): void
    {
        $assetId = $this->assetId();
        $eventId = (string) Str::uuid7();

        $this->app->make(FinanceEventHandler::class)->handle([
            'event_id' => $eventId,
            'event_type' => 'asset.registered.v1',
            'producer' => 'asset-service',
            'aggregate_id' => $assetId,
            'institution_id' => $this->institutionId,
            'correlation_id' => (string) Str::uuid7(),
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'asset_id' => $assetId,
                'institution_id' => $this->institutionId,
                'patrimonial_code' => 'AST-0001',
                'value_unknown' => true,
            ],
        ]);

        $valuation = AssetValuation::where('asset_id', $assetId)->sole();
        $this->assertFalse($valuation->is_determinate);
        $this->assertNull($valuation->amount);
        $this->assertSame('acquisition', $valuation->valuation_type->value);
    }
}
