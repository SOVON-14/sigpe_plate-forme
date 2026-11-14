<?php

namespace Tests\Feature;

use App\Models\CustodyRecord;
use App\Models\MovementOperation;
use App\Models\OutboxMessage;
use App\Services\MovementService;
use App\Services\TokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MovementApiTest extends TestCase
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

    private function claims(array $overrides = []): array
    {
        return array_merge([
            'sub' => $this->sub,
            'permissions' => ['movement.read', 'movement.custody.manage', 'movement.operations.manage', 'movement.shared_rights.manage'],
            'institution_id' => $this->institutionId,
        ], $overrides);
    }

    private function fakeToken(array $claims): void
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

    private function auth(array $claims = []): array
    {
        $this->fakeToken($this->claims($claims));

        return ['Authorization' => 'Bearer fake-token'];
    }

    private function assetId(): string
    {
        return (string) Str::uuid7();
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('service', 'movement-service');
    }

    public function test_requests_without_token_are_rejected(): void
    {
        $this->getJson('/api/v1/operations')->assertUnauthorized();
        $this->getJson('/api/v1/custody')->assertUnauthorized();
    }

    public function test_missing_permission_is_forbidden(): void
    {
        $this->fakeToken($this->claims(['permissions' => []]));

        $this->getJson('/api/v1/operations', ['Authorization' => 'Bearer fake-token'])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_create_custody_and_close_it(): void
    {
        $assetId = $this->assetId();
        $holderRef = (string) Str::uuid7();

        $response = $this->postJson('/api/v1/custody', [
            'asset_id' => $assetId,
            'holder_type' => 'organizational_unit',
            'holder_ref' => $holderRef,
            'custody_role' => 'physical_custodian',
            'motif' => 'Affectation initiale',
        ], $this->auth());

        $response->assertCreated()
            ->assertJsonPath('data.holder_type', 'organizational_unit')
            ->assertJsonPath('data.custody_role', 'physical_custodian')
            ->assertJsonPath('data.institution_id', $this->institutionId);

        $custodyId = $response->json('data.id');

        $this->postJson("/api/v1/custody/{$custodyId}/close", [
            'motif' => 'Fin de garde',
        ], $this->auth())->assertOk();

        $this->assertNotNull(CustodyRecord::find($custodyId)?->valid_to);
    }

    public function test_assignment_operation_creates_custody_immediately(): void
    {
        $assetId = $this->assetId();
        $holderRef = (string) Str::uuid7();

        $response = $this->postJson('/api/v1/operations', [
            'type' => 'assignment',
            'asset_ids' => [$assetId],
            'holder_type' => 'account',
            'holder_ref' => $holderRef,
            'custody_role' => 'authorized_user',
            'motif' => 'Affectation agent',
        ], $this->auth());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $operation = MovementOperation::findOrFail($response->json('data.id'));
        $this->assertSame('completed', $operation->status->value);
        $this->assertDatabaseHas('custody_records', [
            'asset_id' => $assetId,
            'custody_role' => 'authorized_user',
            'source_operation_id' => $operation->id,
        ]);
    }

    public function test_internal_transfer_full_lifecycle(): void
    {
        $assetId = $this->assetId();
        $destinationUnit = (string) Str::uuid7();

        $create = $this->postJson('/api/v1/operations', [
            'type' => 'internal_transfer',
            'asset_ids' => [$assetId],
            'origin_unit_id' => (string) Str::uuid7(),
            'destination_unit_id' => $destinationUnit,
            'motif' => 'Mutation interne',
        ], $this->auth());

        $create->assertCreated()
            ->assertJsonPath('data.status', 'pending_departure');

        $operationId = $create->json('data.id');

        $this->postJson("/api/v1/operations/{$operationId}/confirm-departure", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_reception');

        $this->postJson("/api/v1/operations/{$operationId}/confirm-reception", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertTrue(
            OutboxMessage::where('event_type', 'movement.internal_transfer.completed')
                ->where('aggregate_id', $operationId)
                ->exists()
        );
    }

    public function test_interministerial_transfer_respects_tec017(): void
    {
        $assetId = $this->assetId();
        $destinationInstitution = (string) Str::uuid7();
        $workflowInstanceId = (string) Str::uuid7();

        // Soumission avec circuit : statut pending_validation.
        $create = $this->postJson('/api/v1/operations', [
            'type' => 'interministerial_transfer',
            'asset_ids' => [$assetId],
            'destination_institution_id' => $destinationInstitution,
            'motif' => 'Transfert ministeriel',
        ], $this->auth());

        $create->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $operationId = $create->json('data.id');

        $this->postJson("/api/v1/operations/{$operationId}/submit", [
            'workflow_instance_id' => $workflowInstanceId,
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'pending_validation');

        // Simule la completion du circuit Workflow (consommateur).
        $this->app->make(MovementService::class)->handleWorkflowOutcome(
            $workflowInstanceId,
            'approved',
            $this->sub,
        );

        $this->postJson("/api/v1/operations/{$operationId}/confirm-departure", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_reception');

        $this->postJson("/api/v1/operations/{$operationId}/confirm-reception", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_asset_confirmation');

        // Regle absolue : PAS d'evenement completed avant Asset.
        $this->assertFalse(
            OutboxMessage::where('event_type', 'movement.interministerial_transfer.completed')
                ->where('aggregate_id', $operationId)
                ->exists()
        );

        // Asset confirme le rattachement -> cloture + evenement.
        $this->app->make(MovementService::class)->recordAssetRattachementConfirmation(
            MovementOperation::findOrFail($operationId),
            [$assetId => 'PAT-2026-000042'],
        );

        $this->assertTrue(
            OutboxMessage::where('event_type', 'movement.interministerial_transfer.completed')
                ->where('aggregate_id', $operationId)
                ->exists()
        );
        $this->assertSame('completed', MovementOperation::findOrFail($operationId)->status->value);
    }

    public function test_interministerial_same_institution_is_rejected(): void
    {
        $this->postJson('/api/v1/operations', [
            'type' => 'interministerial_transfer',
            'asset_ids' => [$this->assetId()],
            'destination_institution_id' => $this->institutionId,
        ], $this->auth())->assertUnprocessable();
    }

    public function test_loan_lifecycle_via_api(): void
    {
        $assetId = $this->assetId();
        $lender = (string) Str::uuid7();
        $workflowInstanceId = (string) Str::uuid7();

        $create = $this->postJson('/api/v1/operations', [
            'type' => 'loan',
            'asset_ids' => [$assetId],
            'destination_institution_id' => $lender,
            'loan_start_at' => now()->toIso8601String(),
            'loan_end_at' => now()->addDays(30)->toIso8601String(),
            'motif' => 'Pret longue duree',
        ], $this->auth());

        $create->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $operationId = $create->json('data.id');

        $this->postJson("/api/v1/operations/{$operationId}/submit", [
            'workflow_instance_id' => $workflowInstanceId,
        ], $this->auth());

        $this->app->make(MovementService::class)->handleWorkflowOutcome(
            $workflowInstanceId,
            'approved',
            $this->sub,
        );

        $this->assertSame('active', MovementOperation::findOrFail($operationId)->status->value);

        $this->postJson("/api/v1/operations/{$operationId}/return", [
            'comment' => 'Retour conforme',
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'returned');

        $this->assertTrue(
            OutboxMessage::where('event_type', 'movement.loan.returned')
                ->where('aggregate_id', $operationId)
                ->exists()
        );
    }

    public function test_loan_requires_end_date(): void
    {
        $this->postJson('/api/v1/operations', [
            'type' => 'loan',
            'asset_ids' => [$this->assetId()],
            'destination_institution_id' => (string) Str::uuid7(),
        ], $this->auth())->assertUnprocessable();
    }

    public function test_shared_right_lifecycle(): void
    {
        $assetId = $this->assetId();
        $beneficiary = (string) Str::uuid7();

        $create = $this->postJson('/api/v1/shared-rights', [
            'asset_id' => $assetId,
            'responsible_institution_id' => $this->institutionId,
            'beneficiary_institution_id' => $beneficiary,
            'usage_scope' => 'Usage ponctuel sur site',
            'convention_ref' => 'CONV-2026-001',
        ], $this->auth());

        $create->assertCreated()
            ->assertJsonPath('data.status', 'active');

        $rightId = $create->json('data.id');

        // Doublon actif refuse.
        $this->postJson('/api/v1/shared-rights', [
            'asset_id' => $assetId,
            'responsible_institution_id' => $this->institutionId,
            'beneficiary_institution_id' => $beneficiary,
            'usage_scope' => 'Doublon',
        ], $this->auth())->assertStatus(409);

        $this->postJson("/api/v1/shared-rights/{$rightId}/suspend", [
            'motif' => 'Contentieux en cours',
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->postJson("/api/v1/shared-rights/{$rightId}/terminate", [
            'motif' => 'Fin de convention',
        ], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'terminated');
    }

    public function test_cross_institution_access_is_denied(): void
    {
        $foreignInstitution = (string) Str::uuid7();
        $operation = MovementOperation::create([
            'type' => 'internal_transfer',
            'status' => 'pending_departure',
            'institution_id' => $foreignInstitution,
            'created_by' => (string) Str::uuid7(),
        ]);

        $this->getJson("/api/v1/operations/{$operation->id}", $this->auth())
            ->assertForbidden();
    }

    public function test_idempotent_replay_on_operation_creation(): void
    {
        $key = 'idem-'.Str::random(16);
        $payload = [
            'type' => 'assignment',
            'asset_ids' => [$this->assetId()],
            'holder_type' => 'institution',
            'holder_ref' => $this->institutionId,
            'custody_role' => 'administrative_responsible',
        ];

        $headers = $this->auth() + ['Idempotency-Key' => $key];

        $first = $this->postJson('/api/v1/operations', $payload, $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/v1/operations', $payload, $headers);
        $second->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, MovementOperation::count());
    }
}
