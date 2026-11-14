<?php

namespace Tests\Feature;

use App\Models\InboxMessage;
use App\Models\Intervention;
use App\Models\MaintenanceRequest;
use App\Models\OutboxMessage;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\MaintenanceEventHandler;
use App\Services\MaintenanceService;
use App\Services\TokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenanceApiTest extends TestCase
{
    use RefreshDatabase;

    private string $institutionId;

    private string $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institutionId = (string) Str::uuid7();
        $this->sub = (string) Str::uuid7();
    }

    private function auth(array $claims = []): array
    {
        $merged = array_merge([
            'sub' => $this->sub,
            'permissions' => [
                'maintenance.read',
                'maintenance.requests.manage',
                'maintenance.plans.manage',
                'maintenance.interventions.manage',
                'maintenance.suppliers.manage',
                'maintenance.stock.manage',
            ],
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

    private function requestPayload(array $overrides = []): array
    {
        return array_merge([
            'asset_id' => (string) Str::uuid7(),
            'type' => 'corrective',
            'description' => 'Panne du climatiseur du bureau',
            'urgency' => 'normal',
        ], $overrides);
    }

    private function validatedRequest(): MaintenanceRequest
    {
        $service = $this->app->make(MaintenanceService::class);
        $request = $service->createRequest($this->requestPayload(), $this->institutionId, $this->sub);
        $instanceId = (string) Str::uuid7();
        $service->submitToWorkflow($request, ['workflow_instance_id' => $instanceId], $this->sub);
        $service->handleWorkflowOutcome($instanceId, 'approved', $this->sub);

        return $request->fresh();
    }

    private function completedIntervention(MaintenanceRequest $request): Intervention
    {
        $service = $this->app->make(MaintenanceService::class);
        $intervention = $service->createIntervention([
            'request_id' => $request->id,
            'asset_id' => $request->asset_id,
            'type' => 'corrective',
            'description' => 'Remplacement du compresseur',
            'provider_kind' => 'internal',
            'assigned_account_id' => $this->sub,
        ], $this->institutionId, $this->sub);
        $service->startIntervention($intervention, null);
        $service->completeIntervention($intervention->fresh(), [
            'costs' => [
                'labor' => 50000, 'parts' => 120000, 'supplies' => 5000,
                'transport' => 10000, 'external_services' => 0, 'other' => 0,
            ],
            'outcome_note' => 'Compresseur remplace, test ok.',
        ], null);

        return $intervention->fresh();
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('service', 'maintenance-service');
    }

    public function test_requests_require_auth_and_permission(): void
    {
        $this->getJson('/api/v1/maintenance-requests')->assertUnauthorized();

        $this->app->instance(TokenValidator::class, new class implements TokenValidator
        {
            public function validate(string $token): array
            {
                return ['sub' => (string) Str::uuid7(), 'permissions' => [], 'institution_id' => null];
            }
        });

        $this->getJson('/api/v1/maintenance-requests', ['Authorization' => 'Bearer fake'])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_create_and_submit_request_via_workflow(): void
    {
        $create = $this->postJson('/api/v1/maintenance-requests', $this->requestPayload(), $this->auth());
        $create->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.type', 'corrective')
            ->assertJsonPath('data.institution_id', $this->institutionId);

        $this->assertMatchesRegularExpression('/^MNT-\d{4}-\d{6}$/', $create->json('data.reference'));

        $id = $create->json('data.id');
        $instanceId = (string) Str::uuid7();

        $this->postJson("/api/v1/maintenance-requests/{$id}/submit", [
            'workflow_instance_id' => $instanceId,
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'pending_validation');

        // Outcome Workflow via evenement consomme
        $handler = $this->app->make(MaintenanceEventHandler::class);
        $result = $handler->handle([
            'event_id' => (string) Str::uuid7(),
            'event_type' => 'workflow.instance_completed',
            'producer' => 'workflow-service',
            'correlation_id' => (string) Str::uuid7(),
            'data' => [
                'instance_id' => $instanceId,
                'operation_type' => 'maintenance',
                'outcome' => 'approved',
                'decided_by' => (string) Str::uuid7(),
            ],
        ]);

        $this->assertSame('processed', $result);
        $this->assertSame('validated', MaintenanceRequest::findOrFail($id)->status->value);
        $this->assertTrue(OutboxMessage::where('event_type', 'maintenance.request.validated')->exists());
    }

    public function test_urgent_track_validates_immediately_with_a_posteriori_control(): void
    {
        $response = $this->postJson('/api/v1/maintenance-requests', $this->requestPayload([
            'is_urgent_track' => true,
            'urgent_justification' => 'Fuite active risque electrocution',
        ]), $this->auth());
        $response->assertCreated();

        $id = $response->json('data.id');
        $this->postJson("/api/v1/maintenance-requests/{$id}/submit", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'validated');
    }

    public function test_intervention_full_cycle_with_detailed_costs(): void
    {
        $request = $this->validatedRequest();

        $create = $this->postJson('/api/v1/interventions', [
            'request_id' => $request->id,
            'asset_id' => $request->asset_id,
            'type' => 'corrective',
            'description' => 'Depannage groupe electrogene',
            'provider_kind' => 'internal',
            'assigned_account_id' => $this->sub,
        ], $this->auth());
        $create->assertCreated();
        $this->assertMatchesRegularExpression('/^INT-\d{4}-\d{6}$/', $create->json('data.reference'));

        $id = $create->json('data.id');

        // Demande passee a planned
        $this->assertSame('planned', $request->fresh()->status->value);

        $this->postJson("/api/v1/interventions/{$id}/start", [], $this->auth())
            ->assertOk()->assertJsonPath('data.status', 'in_progress');

        // Completion sans couts -> 422
        $this->postJson("/api/v1/interventions/{$id}/complete", [
            'costs' => ['labor' => 1000],
            'outcome_note' => 'ok',
        ], $this->auth())->assertUnprocessable();

        $this->postJson("/api/v1/interventions/{$id}/complete", [
            'costs' => [
                'labor' => 80000, 'parts' => 25000, 'supplies' => 3000,
                'transport' => 5000, 'external_services' => 0, 'other' => 2000,
            ],
            'outcome_note' => 'Groupe demarre, filtres remplaces.',
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.costs.total', 115000);

        // Demande et intervention terminees + evenements
        $this->assertSame('completed', $request->fresh()->status->value);
        foreach (['maintenance.intervention.started', 'maintenance.intervention.completed'] as $eventType) {
            $this->assertTrue(OutboxMessage::where('event_type', $eventType)->exists(), "Manquant : {$eventType}");
        }
    }

    public function test_intervention_lifecycle_guards(): void
    {
        $request = $this->validatedRequest();
        $service = $this->app->make(MaintenanceService::class);
        $intervention = $service->createIntervention([
            'asset_id' => $request->asset_id,
            'type' => 'preventive',
            'description' => 'Visite technique semestrielle',
            'provider_kind' => 'internal',
        ], $this->institutionId, $this->sub);

        // Cancel possible depuis planned
        $this->postJson("/api/v1/interventions/{$intervention->id}/cancel", [
            'motif' => 'Bien indisponible',
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertTrue(OutboxMessage::where('event_type', 'maintenance.intervention.cancelled')->exists());
    }

    public function test_preventive_plan_execution(): void
    {
        $request = $this->validatedRequest();

        $plan = $this->postJson('/api/v1/intervention-plans', [
            'asset_id' => $request->asset_id,
            'type' => 'preventive',
            'description' => 'Revision trimestrielle groupe',
            'trigger' => [
                'kind' => 'date',
                'interval_value' => 3,
                'interval_unit' => 'months',
                'next_due_at' => now()->addMonth()->toDateString(),
            ],
        ], $this->auth());
        $plan->assertCreated()->assertJsonPath('data.status', 'active');
        $planId = $plan->json('data.id');

        $execute = $this->postJson("/api/v1/intervention-plans/{$planId}/execute", [
            'planned_at' => now()->addWeek()->toIso8601String(),
        ], $this->auth());
        $execute->assertOk();
        $this->assertNotNull($execute->json('data.intervention_id'));
        $this->assertTrue(OutboxMessage::where('event_type', 'maintenance.plan.scheduled')->exists());
    }

    public function test_external_intervention_requires_supplier(): void
    {
        $request = $this->validatedRequest();

        $this->postJson('/api/v1/interventions', [
            'request_id' => $request->id,
            'asset_id' => $request->asset_id,
            'type' => 'corrective',
            'description' => 'Reparation externe',
            'provider_kind' => 'external',
        ], $this->auth())->assertUnprocessable(); // supplier_id requis par la validation

        $supplier = $this->postJson('/api/v1/suppliers', [
            'name' => 'Atelier Froid Plus',
            'specialty' => 'Climatisation',
            'scope' => 'ministerial',
        ], $this->auth());
        $supplier->assertCreated();
        $supplierId = $supplier->json('data.id');

        $intervention = $this->postJson('/api/v1/interventions', [
            'request_id' => $request->id,
            'asset_id' => $request->asset_id,
            'type' => 'corrective',
            'description' => 'Reparation externe',
            'provider_kind' => 'external',
            'supplier_id' => $supplierId,
        ], $this->auth());
        $intervention->assertCreated()
            ->assertJsonPath('data.provider_kind', 'external')
            ->assertJsonPath('data.supplier_id', $supplierId);
    }

    public function test_supplier_evaluation_after_completed_intervention(): void
    {
        $request = $this->validatedRequest();
        $supplier = Supplier::create([
            'institution_id' => $this->institutionId,
            'scope' => 'ministerial',
            'name' => 'ElectroPro',
            'specialty' => 'Electricite',
            'is_active' => true,
            'created_by' => $this->sub,
        ]);

        $service = $this->app->make(MaintenanceService::class);
        $intervention = $service->createIntervention([
            'asset_id' => $request->asset_id,
            'type' => 'corrective',
            'description' => 'Depannage tableau electrique',
            'provider_kind' => 'external',
            'supplier_id' => $supplier->id,
        ], $this->institutionId, $this->sub);
        $service->startIntervention($intervention, null);
        $service->completeIntervention($intervention->fresh(), [
            'costs' => ['labor' => 0, 'parts' => 40000, 'supplies' => 0, 'transport' => 5000, 'external_services' => 60000, 'other' => 0],
            'outcome_note' => 'Tableau remis en etat.',
        ], null);

        $this->postJson("/api/v1/suppliers/{$supplier->id}/evaluate", [
            'intervention_id' => $intervention->id,
            'quality' => 4, 'timeliness' => 5, 'satisfaction' => 4,
            'comment' => 'Bon travail, delai respecte.',
        ], $this->auth())->assertCreated()
            ->assertJsonPath('data.quality', 4);
    }

    public function test_stock_movements_and_reorder_threshold_event(): void
    {
        $warehouse = $this->postJson('/api/v1/warehouses', [
            'name' => 'Magasin central',
            'location' => 'Batiment A',
        ], $this->auth());
        $warehouse->assertCreated();
        $warehouseId = $warehouse->json('data.id');

        $part = $this->postJson('/api/v1/spare-parts', [
            'reference' => 'FIL-001',
            'name' => 'Filtre a air',
            'unit' => 'unite',
            'unit_cost' => 15000,
            'reorder_threshold' => 5,
        ], $this->auth());
        $part->assertCreated();
        $partId = $part->json('data.id');

        // Entree de 10
        $this->postJson('/api/v1/stock-movements', [
            'spare_part_id' => $partId,
            'warehouse_id' => $warehouseId,
            'type' => 'entry',
            'quantity' => 10,
            'reference' => 'BC-2026-001',
        ], $this->auth())->assertCreated();

        $this->assertSame('10.000', (string) StockLevel::where('spare_part_id', $partId)->first()->quantity);

        // Sortie de 6 -> stock 4 < seuil 5 -> evenement
        $this->postJson('/api/v1/stock-movements', [
            'spare_part_id' => $partId,
            'warehouse_id' => $warehouseId,
            'type' => 'exit',
            'quantity' => 6,
            'reason' => 'Consommation interventions',
        ], $this->auth())->assertCreated();

        $this->assertSame('4.000', (string) StockLevel::where('spare_part_id', $partId)->first()->quantity);
        $this->assertTrue(OutboxMessage::where('event_type', 'maintenance.spare_part.below_threshold')->exists());

        // Sortie superieure au stock -> 422
        $this->postJson('/api/v1/stock-movements', [
            'spare_part_id' => $partId,
            'warehouse_id' => $warehouseId,
            'type' => 'exit',
            'quantity' => 100,
        ], $this->auth())->assertUnprocessable();

        // Transfert entre magasins
        $warehouse2 = Warehouse::create([
            'institution_id' => $this->institutionId,
            'name' => 'Annexe',
            'is_active' => true,
            'created_by' => $this->sub,
        ]);

        $this->postJson('/api/v1/stock-movements', [
            'spare_part_id' => $partId,
            'warehouse_id' => $warehouseId,
            'type' => 'transfer_out',
            'quantity' => 2,
            'target_warehouse_id' => $warehouse2->id,
        ], $this->auth())->assertCreated();

        $this->assertSame('2.000', (string) StockLevel::where('spare_part_id', $partId)->where('warehouse_id', $warehouseId)->first()->quantity);
        $this->assertSame('2.000', (string) StockLevel::where('spare_part_id', $partId)->where('warehouse_id', $warehouse2->id)->first()->quantity);
        // entry + exit + transfer_out + transfer_in (cree automatiquement)
        $this->assertSame(4, StockMovement::count());
    }

    public function test_abac_foreign_institution_denied(): void
    {
        $foreign = (string) Str::uuid7();
        $request = $this->app->make(MaintenanceService::class)->createRequest(
            $this->requestPayload(),
            $foreign,
            (string) Str::uuid7(),
        );

        $this->getJson("/api/v1/maintenance-requests/{$request->id}", $this->auth())
            ->assertForbidden();
    }

    public function test_idempotent_replay(): void
    {
        $key = 'idem-'.Str::random(16);
        $headers = $this->auth() + ['Idempotency-Key' => $key];
        $payload = $this->requestPayload();

        $first = $this->postJson('/api/v1/maintenance-requests', $payload, $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/v1/maintenance-requests', $payload, $headers);
        $second->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, MaintenanceRequest::count());
    }

    public function test_event_deduplication(): void
    {
        $handler = $this->app->make(MaintenanceEventHandler::class);
        $envelope = [
            'event_id' => (string) Str::uuid7(),
            'event_type' => 'workflow.instance_completed',
            'data' => ['instance_id' => (string) Str::uuid7(), 'outcome' => 'approved', 'operation_type' => 'maintenance'],
        ];

        $this->assertSame('ignored', $handler->handle($envelope));
        $this->assertSame('duplicate', $handler->handle($envelope));
        $this->assertSame(1, InboxMessage::count());
    }

    public function test_workflow_rejection(): void
    {
        $service = $this->app->make(MaintenanceService::class);
        $request = $service->createRequest($this->requestPayload(), $this->institutionId, $this->sub);
        $instanceId = (string) Str::uuid7();
        $service->submitToWorkflow($request, ['workflow_instance_id' => $instanceId], $this->sub);
        $service->handleWorkflowOutcome($instanceId, 'rejected', $this->sub, 'Budget insuffisant');

        $this->assertSame('rejected', $request->fresh()->status->value);
        $this->assertTrue(OutboxMessage::where('event_type', 'maintenance.request.rejected')->exists());
    }
}
