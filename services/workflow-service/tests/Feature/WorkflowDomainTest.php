<?php

namespace Tests\Feature;

use App\Enums\InstanceStatus;
use App\Enums\OperationDomain;
use App\Enums\TaskDecision;
use App\Enums\TaskStatus;
use App\Exceptions\DomainException;
use App\Models\OutboxMessage;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowDomainTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowService $service;

    private string $institutionId;

    private string $approverAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WorkflowService::class);
        $this->institutionId = (string) Str::uuid7();
        $this->approverAccount = (string) Str::uuid7();
    }

    private function createWorkflow(array $steps = [], ?string $institutionId = null): Workflow
    {
        return $this->service->createWorkflow([
            'institution_id' => null,
            'domain' => 'transfer',
            'code' => 'wf-'.Str::random(6),
            'name' => 'Circuit test',
            'status' => 'active',
        ], $this->institutionId, $steps !== [] ? $steps : [
            ['step_group' => 1, 'sequence' => 1, 'name' => 'Approbation chef', 'assignee_type' => 'account', 'assignee_ref' => $this->approverAccount, 'mode' => 'all', 'sla_hours' => 48],
            ['step_group' => 2, 'sequence' => 1, 'name' => 'Approbation direction', 'assignee_type' => 'role', 'assignee_ref' => 'admin_ministeriel', 'mode' => 'all'],
        ], $this->approverAccount, ['type' => 'user', 'id' => $this->approverAccount]);
    }

    private function startInstance(Workflow $wf): WorkflowInstance
    {
        return $this->service->startInstance(
            $wf, $this->institutionId, OperationDomain::Transfer,
            (string) Str::uuid7(), $this->approverAccount,
            ['motif' => 'Transfert'], ['type' => 'user', 'id' => $this->approverAccount],
        );
    }

    public function test_create_workflow_with_steps_and_event(): void
    {
        $wf = $this->createWorkflow();

        $this->assertSame(2, $wf->steps->count());
        $this->assertSame('workflow.template_created', OutboxMessage::orderByDesc('id')->first()->event_type);
    }

    public function test_adaptation_requires_national_mandatory_steps(): void
    {
        $national = $this->createWorkflow([
            ['step_group' => 1, 'sequence' => 1, 'name' => 'Visa national', 'assignee_type' => 'role', 'assignee_ref' => 'admin_principal', 'mode' => 'all', 'is_national_obligatory' => true],
        ]);
        $this->assertNull($national->institution_id);

        // Avec parent national + etape obligatoire manquante → refus
        try {
            $this->service->createWorkflow([
                'institution_id' => $this->institutionId,
                'parent_workflow_id' => $national->id,
                'domain' => 'transfer',
                'code' => 'adapt-'.Str::random(5),
                'name' => 'Adaptation',
                'status' => 'active',
            ], $this->institutionId, [
                ['step_group' => 1, 'sequence' => 1, 'name' => 'Autre etape', 'assignee_type' => 'role', 'assignee_ref' => 'x', 'mode' => 'all'],
            ], $this->approverAccount);
            $this->fail('Adaptation sans etape nationale acceptee');
        } catch (DomainException $e) {
            $this->assertSame('WORKFLOW_MISSING_NATIONAL_STEPS', $e->errorCode);
        }
    }

    public function test_sequential_groups_flow_to_approval(): void
    {
        $wf = $this->createWorkflow();
        $instance = $this->startInstance($wf);

        $this->assertSame(InstanceStatus::InProgress, $instance->status);
        $this->assertSame(1, $instance->tasks->count()); // seul le groupe 1 a des taches
        $this->assertSame('workflow.instance_started', OutboxMessage::orderByDesc('id')->first()->event_type);

        $task1 = $instance->tasks->first();
        [$task, $instance] = $this->service->decideTask($task1, TaskDecision::Approve, null, $this->approverAccount);

        // Groupe 2 active → nouvelle tache
        $instance = $instance->fresh();
        $this->assertSame(InstanceStatus::InProgress, $instance->status);
        $this->assertSame(2, $instance->current_step_group);
        $this->assertSame(2, $instance->tasks()->count());

        // Tache role-based : decidable via permission workflow.tasks.decide
        $task2 = $instance->tasks()->where('step_group', 2)->first();
        $this->assertTrue($task2->canBeDecidedBy('nimporte-qui', ['workflow.tasks.decide']));
        $this->assertFalse($task2->canBeDecidedBy('nimporte-qui', ['autre.permission']));

        [$task2, $instance] = $this->service->decideTask($task2, TaskDecision::Approve, null, $this->approverAccount);

        $instance = $instance->fresh();
        $this->assertSame(InstanceStatus::Approved, $instance->status);
        $this->assertSame('approved', $instance->decided_outcome);
        $this->assertSame('workflow.instance_completed', OutboxMessage::orderByDesc('id')->first()->event_type);
    }

    public function test_reject_and_correction_paths(): void
    {
        $wf = $this->createWorkflow();

        // Rejet
        $i1 = $this->startInstance($wf);
        $t = $i1->tasks->first();
        [, $i1] = $this->service->decideTask($t, TaskDecision::Reject, 'Motif du refus', $this->approverAccount);
        $this->assertSame(InstanceStatus::Rejected, $i1->fresh()->status);

        // Demande de correction → outcome correction_requested
        $i2 = $this->startInstance($wf);
        $t2 = $i2->tasks->first();
        [, $i2] = $this->service->decideTask($t2, TaskDecision::RequestCorrection, 'Corriger la piece', $this->approverAccount);
        $this->assertSame(InstanceStatus::CorrectionRequested, $i2->fresh()->status);
    }

    public function test_reject_requires_comment(): void
    {
        $wf = $this->createWorkflow();
        $instance = $this->startInstance($wf);
        $task = $instance->tasks->first();

        $this->expectException(DomainException::class);
        $this->service->decideTask($task, TaskDecision::Reject, null, $this->approverAccount);
    }

    public function test_parallel_group_any_mode(): void
    {
        $wf = $this->createWorkflow([
            ['step_group' => 1, 'sequence' => 1, 'name' => 'Visa A', 'assignee_type' => 'role', 'assignee_ref' => 'role_a', 'mode' => 'any'],
            ['step_group' => 1, 'sequence' => 2, 'name' => 'Visa B', 'assignee_type' => 'role', 'assignee_ref' => 'role_b', 'mode' => 'any'],
        ]);
        $instance = $this->startInstance($wf);

        $this->assertSame(2, $instance->tasks->count());

        // Une seule approbation suffit (mode any) → instance approved
        $task = $instance->tasks->first();
        [, $instance] = $this->service->decideTask($task, TaskDecision::Approve, null, $this->approverAccount);

        $this->assertSame(InstanceStatus::Approved, $instance->fresh()->status);
    }

    public function test_duplicate_open_instance_rejected(): void
    {
        $wf = $this->createWorkflow();
        $operationRef = (string) Str::uuid7();

        $this->service->startInstance($wf, $this->institutionId, OperationDomain::Transfer, $operationRef, $this->approverAccount);

        $this->expectException(DomainException::class);
        $this->service->startInstance($wf, $this->institutionId, OperationDomain::Transfer, $operationRef, $this->approverAccount);
    }

    public function test_cancel_instance(): void
    {
        $wf = $this->createWorkflow();
        $instance = $this->startInstance($wf);

        $instance = $this->service->cancelInstance($instance, 'Demande retiree', $this->approverAccount);

        $this->assertSame(InstanceStatus::Cancelled, $instance->status);
        $this->assertSame('cancelled', $instance->decided_outcome);
        $this->assertSame(TaskStatus::Cancelled, $instance->tasks()->first()->status);
    }
}
