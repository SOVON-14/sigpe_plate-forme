<?php

namespace Tests\Feature;

use App\Jobs\RequestAssetRattachement;
use App\Models\InboxMessage;
use App\Models\OutboxMessage;
use App\Services\AssetGateway;
use App\Services\MovementEventHandler;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MovementEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private MovementEventHandler $handler;

    private MovementService $service;

    private string $institutionId;

    private string $destinationId;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = $this->app->make(MovementEventHandler::class);
        $this->service = $this->app->make(MovementService::class);
        $this->institutionId = (string) Str::uuid7();
        $this->destinationId = (string) Str::uuid7();
        $this->assetId = (string) Str::uuid7();

        // Les jobs RequestAssetRattachement sont testes separement.
        Queue::fake();
    }

    private function workflowEnvelope(string $instanceId, string $outcome, array $extra = []): array
    {
        return [
            'event_id' => (string) Str::uuid7(),
            'event_type' => 'workflow.instance_completed',
            'event_version' => 1,
            'producer' => 'workflow-service',
            'correlation_id' => (string) Str::uuid7(),
            'data' => array_merge([
                'instance_id' => $instanceId,
                'operation_type' => 'transfer',
                'outcome' => $outcome,
                'decided_by' => (string) Str::uuid7(),
                'decision_comment' => null,
            ], $extra),
        ];
    }

    private function operationAwaitingWorkflow(string $type = 'interministerial_transfer'): array
    {
        $instanceId = (string) Str::uuid7();

        $operation = $this->service->createOperation(
            [
                'type' => $type,
                'destination_institution_id' => $type === 'interministerial_transfer' ? $this->destinationId : null,
                'loan_end_at' => $type === 'loan' ? now()->addDays(10)->toIso8601String() : null,
            ],
            $this->institutionId,
            [$this->assetId],
            (string) Str::uuid7(),
        );

        $this->service->submitToWorkflow($operation, ['workflow_instance_id' => $instanceId], (string) Str::uuid7());

        return [$operation->fresh(), $instanceId];
    }

    public function test_workflow_approved_event_moves_operation_forward(): void
    {
        [$operation, $instanceId] = $this->operationAwaitingWorkflow();

        $result = $this->handler->handle($this->workflowEnvelope($instanceId, 'approved'));

        $this->assertSame('processed', $result);
        $this->assertSame('pending_departure', $operation->fresh()->status->value);
    }

    public function test_workflow_rejected_event_rejects_operation(): void
    {
        [$operation, $instanceId] = $this->operationAwaitingWorkflow();

        $this->handler->handle($this->workflowEnvelope($instanceId, 'rejected', [
            'decision_comment' => 'Budget insuffisant',
        ]));

        $operation = $operation->fresh();
        $this->assertSame('rejected', $operation->status->value);
        $this->assertSame('Budget insuffisant', $operation->decision_comment);
    }

    public function test_duplicate_event_is_not_reprocessed(): void
    {
        [$operation, $instanceId] = $this->operationAwaitingWorkflow();
        $envelope = $this->workflowEnvelope($instanceId, 'rejected');

        $this->assertSame('processed', $this->handler->handle($envelope));
        $this->assertSame('rejected', $operation->fresh()->status->value);

        // Rejeu du meme event_id : ignore, l'operation reste rejected.
        $this->assertSame('duplicate', $this->handler->handle($envelope));
        $this->assertSame(1, InboxMessage::count());
    }

    public function test_unknown_event_is_ignored(): void
    {
        $result = $this->handler->handle([
            'event_id' => (string) Str::uuid7(),
            'event_type' => 'inventory.campaign_created',
            'data' => [],
        ]);

        $this->assertSame('ignored', $result);
    }

    public function test_asset_rattachement_confirmed_completes_transfer(): void
    {
        // Interministerial jusqu'a pending_asset_confirmation.
        [$operation, $instanceId] = $this->operationAwaitingWorkflow();
        $this->handler->handle($this->workflowEnvelope($instanceId, 'approved'));

        $operation = $operation->fresh();
        $this->service->confirmDeparture($operation, [], (string) Str::uuid7());
        $this->service->confirmReception($operation->fresh(), [], (string) Str::uuid7());

        $this->assertSame('pending_asset_confirmation', $operation->fresh()->status->value);

        // Asset confirme -> cloture + evenement completed.
        $result = $this->handler->handle([
            'event_id' => (string) Str::uuid7(),
            'event_type' => 'asset.rattachement_confirmed',
            'producer' => 'asset-service',
            'correlation_id' => (string) Str::uuid7(),
            'data' => [
                'asset_id' => $this->assetId,
                'institution_id' => $this->destinationId,
                'previous_institution_id' => $this->institutionId,
                'patrimonial_code' => 'PAT-2026-000777',
                'previous_patrimonial_code' => 'PAT-2026-000111',
            ],
        ]);

        $this->assertSame('processed', $result);
        $this->assertSame('completed', $operation->fresh()->status->value);
        $this->assertTrue(
            OutboxMessage::where('event_type', 'movement.interministerial_transfer.completed')
                ->where('aggregate_id', $operation->id)
                ->exists()
        );
    }

    public function test_request_asset_rattachement_job_calls_asset_api(): void
    {
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(['access_token' => 'svc-token'], 200),
            '*/assets/*/rattachement' => Http::response(['id' => $this->assetId], 200),
        ]);

        config()->set('services.asset.url', 'http://asset.test/api/v1');
        config()->set('services.keycloak.token_url', 'http://keycloak.test/realms/sigpe/protocol/openid-connect/token');

        [$operation] = $this->operationAwaitingWorkflow();
        $operation->forceFill(['status' => 'pending_asset_confirmation'])->save();

        (new RequestAssetRattachement($operation->id, $this->assetId))->handle(
            $this->app->make(AssetGateway::class)
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), "/assets/{$this->assetId}/rattachement")
            && $request['institution_id'] === $this->destinationId
            && $request->hasHeader('Idempotency-Key', "rattachement:{$operation->id}:{$this->assetId}"));
    }
}
