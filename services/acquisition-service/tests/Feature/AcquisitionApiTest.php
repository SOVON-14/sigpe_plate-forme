<?php

namespace Tests\Feature;

use App\Jobs\RegisterAcceptedItems;
use App\Models\AcquisitionReceptionLine;
use App\Models\AcquisitionRequest;
use App\Models\InboxMessage;
use App\Models\OutboxMessage;
use App\Services\AcquisitionEventHandler;
use App\Services\AcquisitionService;
use App\Services\AssetGateway;
use App\Services\TokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AcquisitionApiTest extends TestCase
{
    use RefreshDatabase;

    private string $institutionId;

    private string $sub;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->institutionId = (string) Str::uuid7();
        $this->sub = (string) Str::uuid7();
    }

    private function auth(array $claims = []): array
    {
        $merged = array_merge([
            'sub' => $this->sub,
            'permissions' => ['acquisition.read', 'acquisition.requests.manage'],
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

    private function requestPayload(?array $items = null): array
    {
        return [
            'title' => 'Acquisition materiel informatique',
            'priority' => 'normal',
            'items' => $items ?? [
                [
                    'description' => 'Ordinateur portable',
                    'quantity_ordered' => 2,
                    'unit' => 'unite',
                    'estimated_unit_value' => 450000,
                ],
            ],
        ];
    }

    private function createValidatedRequest(): AcquisitionRequest
    {
        $service = $this->app->make(AcquisitionService::class);
        $request = $service->createRequest(
            $this->requestPayload(),
            $this->institutionId,
            $this->sub,
        );
        $service->submitToWorkflow($request, ['workflow_instance_id' => (string) Str::uuid7()], $this->sub);
        $instanceId = $request->fresh()->workflow_instance_id;
        $service->handleWorkflowOutcome($instanceId, 'approved', $this->sub);

        return $request->fresh();
    }

    private function selectAndOrder(AcquisitionRequest $request): AcquisitionRequest
    {
        $service = $this->app->make(AcquisitionService::class);
        $service->selectOffer($request, ['supplier_name' => 'Fournisseur SARL'], $this->sub);
        $service->placeOrder($request->fresh(), ['order_ref' => 'CMD-2026-001'], $this->sub);

        return $request->fresh();
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('service', 'acquisition-service');
    }

    public function test_requests_without_token_are_rejected(): void
    {
        $this->getJson('/api/v1/acquisition-requests')->assertUnauthorized();
    }

    public function test_missing_permission_is_forbidden(): void
    {
        $this->fakeTokenOnly(['permissions' => []]);
        $this->getJson('/api/v1/acquisition-requests', ['Authorization' => 'Bearer fake-token'])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    private function fakeTokenOnly(array $claims): void
    {
        $this->app->instance(TokenValidator::class, new class($claims) implements TokenValidator
        {
            public function __construct(private array $claims) {}

            public function validate(string $token): array
            {
                return $this->claims;
            }
        });
    }

    public function test_create_request_generates_reference_and_items(): void
    {
        $response = $this->postJson('/api/v1/acquisition-requests', $this->requestPayload(), $this->auth());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.priority', 'normal')
            ->assertJsonPath('data.institution_id', $this->institutionId);

        $this->assertMatchesRegularExpression('/^ACQ-\d{4}-\d{6}$/', $response->json('data.reference'));
        $this->assertSame(1, AcquisitionRequest::count());
    }

    public function test_full_acquisition_lifecycle_via_api(): void
    {
        // 1. Creation
        $create = $this->postJson('/api/v1/acquisition-requests', $this->requestPayload(), $this->auth());
        $create->assertCreated();
        $requestId = $create->json('data.id');

        // 2. Soumission
        $instanceId = (string) Str::uuid7();
        $this->postJson("/api/v1/acquisition-requests/{$requestId}/submit", [
            'workflow_instance_id' => $instanceId,
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'pending_validation');

        // 3. Outcome Workflow (evenement consomme)
        $handler = $this->app->make(AcquisitionEventHandler::class);
        $result = $handler->handle([
            'event_id' => (string) Str::uuid7(),
            'event_type' => 'workflow.instance_completed',
            'producer' => 'workflow-service',
            'correlation_id' => (string) Str::uuid7(),
            'data' => [
                'instance_id' => $instanceId,
                'operation_type' => 'acquisition',
                'outcome' => 'approved',
                'decided_by' => (string) Str::uuid7(),
            ],
        ]);
        $this->assertSame('processed', $result);
        $this->assertSame('validated', AcquisitionRequest::findOrFail($requestId)->status->value);

        // 4. Selection + commande
        $this->postJson("/api/v1/acquisition-requests/{$requestId}/select", [
            'supplier_name' => 'Fournisseur X',
            'contracted_total_value' => 950000,
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'selected');

        $this->postJson("/api/v1/acquisition-requests/{$requestId}/order", [
            'order_ref' => 'BC-2026-777',
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'ordered');

        // 5. Livraison + reception partielle acceptee
        $delivery = $this->postJson("/api/v1/acquisition-requests/{$requestId}/deliveries", [
            'delivery_note_ref' => 'BL-001',
            'carrier' => 'Transport national',
        ], $this->auth());
        $delivery->assertCreated();
        $deliveryId = $delivery->json('data.id');
        $itemId = AcquisitionRequest::findOrFail($requestId)->items->first()->id;

        $this->postJson("/api/v1/deliveries/{$deliveryId}/receptions", [
            'received_by_name' => 'Magasinier principal',
            'lines' => [
                [
                    'item_id' => $itemId,
                    'quantity_received' => 2,
                    'quantity_accepted' => 2,
                    'conform' => true,
                    'serial_numbers' => ['SN-001', 'SN-002'],
                ],
            ],
        ], $this->auth())->assertCreated()
            ->assertJsonPath('data.status', 'complete');

        // Request passee a received (2/2 acceptes)
        $this->assertSame('received', AcquisitionRequest::findOrFail($requestId)->status->value);

        // Evenements outbox
        foreach ([
            'acquisition.request.created',
            'acquisition.request.submitted',
            'acquisition.request.selected',
            'acquisition.request.ordered',
            'acquisition.delivery.recorded',
            'acquisition.reception.recorded',
            'acquisition.item_accepted',
        ] as $eventType) {
            $this->assertTrue(
                OutboxMessage::where('event_type', $eventType)->exists(),
                "Evenement manquant : {$eventType}"
            );
        }

        // 6. Cloture : refuse tant que les assets ne sont pas enregistres
        $this->postJson("/api/v1/acquisition-requests/{$requestId}/close", [], $this->auth())
            ->assertStatus(409);
    }

    public function test_reception_with_refused_quantity(): void
    {
        $request = $this->selectAndOrder($this->createValidatedRequest());
        $delivery = $this->app->make(AcquisitionService::class)
            ->recordDelivery($request, [], $this->sub);
        $item = $request->items->first();

        $reception = $this->app->make(AcquisitionService::class)->recordReception(
            $delivery,
            [
                'lines' => [[
                    'item_id' => $item->id,
                    'quantity_received' => 2,
                    'quantity_accepted' => 1,
                    'quantity_refused' => 1,
                    'conform' => false,
                    'non_conformity_reason' => 'Unite endommagee a la livraison',
                ]],
            ],
            $this->sub,
        );

        // Toutes les quantites commandees ont ete recues (comptees refusees incluses)
        $this->assertSame('complete', $reception->status->value);
        $this->assertSame('partially_received', $request->fresh()->status->value);
        $this->assertSame('1.000', $item->fresh()->quantity_accepted);
    }

    public function test_cancel_request(): void
    {
        $request = $this->app->make(AcquisitionService::class)->createRequest(
            $this->requestPayload(),
            $this->institutionId,
            $this->sub,
        );

        $this->postJson("/api/v1/acquisition-requests/{$request->id}/cancel", [
            'motif' => 'Besoin devient obsolete',
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_duplicate_institution_scope_denied(): void
    {
        $foreign = (string) Str::uuid7();
        $request = $this->app->make(AcquisitionService::class)->createRequest(
            $this->requestPayload(),
            $foreign,
            (string) Str::uuid7(),
        );

        $this->getJson("/api/v1/acquisition-requests/{$request->id}", $this->auth())
            ->assertForbidden();
    }

    public function test_idempotent_replay(): void
    {
        $key = 'idem-'.Str::random(16);
        $headers = $this->auth() + ['Idempotency-Key' => $key];

        $first = $this->postJson('/api/v1/acquisition-requests', $this->requestPayload(), $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/v1/acquisition-requests', $this->requestPayload(), $headers);
        $second->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, AcquisitionRequest::count());
    }

    public function test_validation_error_is_problem_json(): void
    {
        $this->postJson('/api/v1/acquisition-requests', [
            'title' => 'x',
            'items' => [],
        ], $this->auth())->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_register_accepted_items_job_calls_asset(): void
    {
        Http::fake([
            'keycloak.test/*' => Http::response(['access_token' => 'svc'], 200),
            'asset.test/*' => Http::sequence()
                ->push(['data' => ['id' => (string) Str::uuid7()]], 201)
                ->push(['data' => ['id' => (string) Str::uuid7()]], 201),
        ]);

        config()->set('services.asset.url', 'http://asset.test/api/v1');
        config()->set('services.keycloak.token_url', 'http://keycloak.test/token');

        $request = $this->selectAndOrder($this->createValidatedRequest());
        $delivery = $this->app->make(AcquisitionService::class)
            ->recordDelivery($request, [], $this->sub);
        $item = $request->items->first();

        $reception = $this->app->make(AcquisitionService::class)->recordReception(
            $delivery,
            ['lines' => [[
                'item_id' => $item->id,
                'quantity_received' => 2,
                'quantity_accepted' => 2,
                'serial_numbers' => ['SN-A', 'SN-B'],
            ]]],
            $this->sub,
        );

        $line = AcquisitionReceptionLine::where('reception_id', $reception->id)->firstOrFail();
        Queue::assertPushed(RegisterAcceptedItems::class);

        // Execution manuelle du job (Queue::fake actif)
        (new RegisterAcceptedItems($line->id))->handle(
            $this->app->make(AssetGateway::class),
            $this->app->make(AcquisitionService::class),
        );

        $line->refresh();
        $this->assertCount(2, $line->asset_ids);
        $this->assertNotNull($line->asset_id);

        // Cloture possible maintenant
        $this->app->make(AcquisitionService::class)->closeRequest($request->fresh(), $this->sub);
        $this->assertSame('closed', $request->fresh()->status->value);
        $this->assertTrue(OutboxMessage::where('event_type', 'acquisition.request.closed')->exists());
    }

    public function test_event_deduplication(): void
    {
        $handler = $this->app->make(AcquisitionEventHandler::class);
        $envelope = [
            'event_id' => (string) Str::uuid7(),
            'event_type' => 'workflow.instance_completed',
            'data' => ['instance_id' => (string) Str::uuid7(), 'outcome' => 'approved', 'operation_type' => 'acquisition'],
        ];

        $this->assertSame('ignored', $handler->handle($envelope)); // requete inconnue
        $this->assertSame('duplicate', $handler->handle($envelope)); // meme event_id
        $this->assertSame(1, InboxMessage::count());
    }
}
