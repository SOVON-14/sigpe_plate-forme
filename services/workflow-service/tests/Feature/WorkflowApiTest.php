<?php

namespace Tests\Feature;

use App\Models\OutboxMessage;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\TokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowApiTest extends TestCase
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

    private function claims(array $overrides = []): array
    {
        return array_merge([
            'sub' => $this->sub,
            'permissions' => ['workflow.templates.read', 'workflow.templates.manage', 'workflow.instances.read', 'workflow.instances.manage', 'workflow.tasks.decide'],
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

    private function workflowPayload(array $overrides = []): array
    {
        return array_merge([
            'domain' => 'transfer',
            'code' => 'wf-'.Str::lower(Str::random(8)),
            'name' => 'Circuit de test',
            'status' => 'active',
            'steps' => [
                ['step_group' => 1, 'sequence' => 1, 'name' => 'Approbation chef', 'assignee_type' => 'account', 'assignee_ref' => $this->sub, 'mode' => 'all', 'sla_hours' => 48],
                ['step_group' => 2, 'sequence' => 1, 'name' => 'Approbation direction', 'assignee_type' => 'role', 'assignee_ref' => 'admin_ministeriel', 'mode' => 'all'],
            ],
        ], $overrides);
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('service', 'workflow-service');
    }

    public function test_requests_without_token_are_rejected(): void
    {
        $this->getJson('/api/v1/workflows')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_missing_permission_is_forbidden(): void
    {
        $this->fakeToken($this->claims(['permissions' => []]));

        $this->getJson('/api/v1/workflows', ['Authorization' => 'Bearer x'])
            ->assertForbidden();
    }

    public function test_create_and_list_workflows(): void
    {
        $headers = $this->auth();

        $response = $this->postJson('/api/v1/workflows', $this->workflowPayload(), $headers);
        if ($response->status() !== 201) {
            fwrite(STDERR, "\n".$response->getContent()."\n");
        }
        $response->assertCreated()
            ->assertJsonPath('data.code', fn ($v) => str_starts_with($v, 'wf-'))
            ->assertJsonPath('data.institution_id', $this->institutionId)
            ->assertJsonPath('data.steps', fn ($steps) => count($steps) === 2);

        $this->assertDatabaseHas('workflows', ['code' => $response->json('data.code')]);
        $this->assertSame('workflow.template_created', OutboxMessage::latest('created_at')->first()->event_type);

        $this->getJson('/api/v1/workflows', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_national_workflow_requires_central_permission(): void
    {
        $headers = $this->auth();

        // institution_id explicite a null sans droit central → 403
        $this->postJson('/api/v1/workflows', $this->workflowPayload(['institution_id' => null]), $headers)
            ->assertForbidden();

        // Avec le droit central → cree, institution_id NULL
        $headers = $this->auth(['permissions' => array_merge($this->claims()['permissions'], ['workflow.templates.national'])]);
        $response = $this->postJson('/api/v1/workflows', $this->workflowPayload(['institution_id' => null]), $headers);
        $response->assertCreated()->assertJsonPath('data.institution_id', null);
    }

    public function test_workflow_foreign_institution_is_invisible(): void
    {
        $headers = $this->auth();
        $wf = Workflow::create([
            'institution_id' => (string) Str::uuid7(),
            'domain' => 'transfer', 'code' => 'other-'.Str::lower(Str::random(8)),
            'name' => 'Autre institution', 'status' => 'active', 'version' => 1,
        ]);

        $this->getJson("/api/v1/workflows/{$wf->id}", $headers)->assertForbidden();
        $this->getJson('/api/v1/workflows', $headers)->assertJsonPath('meta.total', 0);
    }

    public function test_full_instance_lifecycle_via_api(): void
    {
        $headers = $this->auth();

        $wfId = $this->postJson('/api/v1/workflows', $this->workflowPayload(), $headers)->json('data.id');

        $instance = $this->postJson('/api/v1/workflow-instances', [
            'workflow_id' => $wfId,
            'institution_id' => $this->institutionId,
            'operation_type' => 'transfer',
            'operation_ref' => (string) Str::uuid7(),
            'context' => ['motif' => 'Test'],
        ], $headers)->assertCreated();

        $instanceId = $instance->json('data.id');
        $instance->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.current_step_group', 1);

        $this->getJson("/api/v1/workflow-instances/{$instanceId}/tasks", $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $taskId = $instance->json('data.tasks.0.id');

        // Decision approve → groupe 2 → approve → completed
        $this->postJson("/api/v1/tasks/{$taskId}/decision", ['decision' => 'approve'], $headers)
            ->assertOk()
            ->assertJsonPath('data.instance.status', 'in_progress');

        $task2 = WorkflowTask::where('instance_id', $instanceId)->where('step_group', 2)->firstOrFail();
        $this->postJson("/api/v1/tasks/{$task2->id}/decision", ['decision' => 'approve'], $headers)
            ->assertOk()
            ->assertJsonPath('data.instance.status', 'approved')
            ->assertJsonPath('data.instance.decided_outcome', 'approved');

        $this->assertTrue(
            OutboxMessage::where('aggregate_id', $instanceId)
                ->where('event_type', 'workflow.instance_completed')
                ->exists(),
        );
    }

    public function test_reject_requires_motif_and_ends_instance(): void
    {
        $headers = $this->auth();
        $wfId = $this->postJson('/api/v1/workflows', $this->workflowPayload(), $headers)->json('data.id');
        $instanceId = $this->postJson('/api/v1/workflow-instances', [
            'workflow_id' => $wfId,
            'institution_id' => $this->institutionId,
            'operation_type' => 'transfer',
            'operation_ref' => (string) Str::uuid7(),
        ], $headers)->json('data.id');

        $task = WorkflowTask::where('instance_id', $instanceId)->firstOrFail();

        $this->postJson("/api/v1/tasks/{$task->id}/decision", ['decision' => 'reject'], $headers)
            ->assertUnprocessable();

        $this->postJson("/api/v1/tasks/{$task->id}/decision", ['decision' => 'reject', 'comment' => 'Piece manquante'], $headers)
            ->assertOk()
            ->assertJsonPath('data.instance.status', 'rejected');
    }

    public function test_task_decision_by_non_assignee_is_denied(): void
    {
        $headers = $this->auth();
        $wfId = $this->postJson('/api/v1/workflows', $this->workflowPayload(), $headers)->json('data.id');
        $instanceId = $this->postJson('/api/v1/workflow-instances', [
            'workflow_id' => $wfId,
            'institution_id' => $this->institutionId,
            'operation_type' => 'transfer',
            'operation_ref' => (string) Str::uuid7(),
        ], $headers)->json('data.id');

        // Tache groupe 2 = role-based : permission decide suffit.
        // On cree un autre compte sans droit sur la tache account du groupe 1.
        $task = WorkflowTask::where('instance_id', $instanceId)->where('step_group', 1)->firstOrFail();
        $this->assertSame($this->sub, $task->assignee_ref);

        // Un autre sub ne peut pas decider la tache account
        $otherHeaders = $this->auth(['sub' => (string) Str::uuid7()]);
        $this->postJson("/api/v1/tasks/{$task->id}/decision", ['decision' => 'approve'], $otherHeaders)
            ->assertForbidden();
    }

    public function test_idempotent_replay_on_instance_start(): void
    {
        $headers = $this->auth();
        $wfId = $this->postJson('/api/v1/workflows', $this->workflowPayload(), $headers)->json('data.id');

        $payload = [
            'workflow_id' => $wfId,
            'institution_id' => $this->institutionId,
            'operation_type' => 'transfer',
            'operation_ref' => (string) Str::uuid7(),
        ];
        $headers['Idempotency-Key'] = 'idem-'.Str::random(10);

        $first = $this->postJson('/api/v1/workflow-instances', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/v1/workflow-instances', $payload, $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, WorkflowInstance::count());
    }

    public function test_cancel_instance_via_api(): void
    {
        $headers = $this->auth();
        $wfId = $this->postJson('/api/v1/workflows', $this->workflowPayload(), $headers)->json('data.id');
        $instanceId = $this->postJson('/api/v1/workflow-instances', [
            'workflow_id' => $wfId,
            'institution_id' => $this->institutionId,
            'operation_type' => 'transfer',
            'operation_ref' => (string) Str::uuid7(),
        ], $headers)->json('data.id');

        $this->postJson("/api/v1/workflow-instances/{$instanceId}/cancel", ['motif' => 'Retrait'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }
}
