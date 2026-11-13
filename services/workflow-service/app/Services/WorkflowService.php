<?php

namespace App\Services;

use App\Enums\AssigneeType;
use App\Enums\InstanceStatus;
use App\Enums\OperationDomain;
use App\Enums\StepMode;
use App\Enums\TaskDecision;
use App\Enums\TaskStatus;
use App\Enums\WorkflowStatus;
use App\Exceptions\DomainException;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use App\Models\WorkflowTask;
use Illuminate\Support\Facades\DB;

class WorkflowService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    /**
     * Cree un workflow (national si institution_id NULL, sinon adaptation).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function createWorkflow(array $data, ?string $institutionId, array $steps, ?string $createdBy, ?array $actor = null, ?string $correlationId = null): Workflow
    {
        $institutionId = array_key_exists('institution_id', $data)
            ? $data['institution_id']
            : $institutionId;
        $parentId = $data['parent_workflow_id'] ?? null;

        if ($parentId !== null) {
            $parent = Workflow::findOrFail($parentId);
            if (! $parent->isNational()) {
                throw new DomainException('WORKFLOW_PARENT_NOT_NATIONAL', 'Seul un workflow national peut etre adapte.');
            }
            // Les etapes nationales obligatoires du parent doivent etre conservees.
            $mandatory = $parent->steps()->where('is_national_obligatory', true)->pluck('name')->all();
            $provided = array_column($steps, 'name');
            $missing = array_diff($mandatory, $provided);
            if ($missing !== []) {
                throw new DomainException(
                    'WORKFLOW_MISSING_NATIONAL_STEPS',
                    'Etapes nationales obligatoires manquantes : '.implode(', ', $missing),
                );
            }
        }

        return DB::transaction(function () use ($data, $institutionId, $parentId, $steps, $createdBy, $actor, $correlationId) {
            $workflow = Workflow::create([
                'institution_id' => $institutionId,
                'parent_workflow_id' => $parentId,
                'domain' => OperationDomain::from($data['domain']),
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'version' => 1,
                'status' => WorkflowStatus::from($data['status'] ?? 'draft'),
                'created_by' => $createdBy,
            ]);

            foreach ($steps as $step) {
                WorkflowStep::create([
                    'workflow_id' => $workflow->id,
                    'step_group' => (int) $step['step_group'],
                    'sequence' => (int) ($step['sequence'] ?? 1),
                    'name' => $step['name'],
                    'assignee_type' => AssigneeType::from($step['assignee_type']),
                    'assignee_ref' => $step['assignee_ref'],
                    'mode' => StepMode::from($step['mode'] ?? 'all'),
                    'sla_hours' => $step['sla_hours'] ?? null,
                    'is_national_obligatory' => (bool) ($step['is_national_obligatory'] ?? false),
                ]);
            }

            $this->outbox->record(
                'workflow.template_created',
                'workflow',
                $workflow->id,
                [
                    'workflow_id' => $workflow->id,
                    'institution_id' => $workflow->institution_id,
                    'parent_workflow_id' => $parentId,
                    'domain' => $workflow->domain->value,
                    'code' => $workflow->code,
                    'name' => $workflow->name,
                    'description' => $workflow->description,
                    'version' => $workflow->version,
                    'status' => $workflow->status->value,
                    'steps_count' => count($steps),
                ],
                correlationId: $correlationId,
                institutionId: $institutionId,
                actor: $actor,
            );

            return $workflow->load('steps');
        });
    }

    /**
     * Demarre une instance : fige la version, cree les taches du 1er groupe.
     *
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function startInstance(Workflow $workflow, string $institutionId, OperationDomain $operationType, string $operationRef, string $startedBy, ?array $context = null, ?array $actor = null, ?string $correlationId = null): WorkflowInstance
    {
        if ($workflow->status !== WorkflowStatus::Active) {
            throw new DomainException('WORKFLOW_NOT_ACTIVE', 'Seul un workflow actif peut demarrer une instance.');
        }

        // Visibilite institutionnelle : un workflow ministeriel n'est
        // utilisable que par son institution ; le national par tous.
        if (! $workflow->isNational() && $workflow->institution_id !== $institutionId) {
            throw new DomainException('WORKFLOW_FOREIGN_INSTITUTION', 'Workflow d\'une autre institution.');
        }

        // Une seule instance ouverte par operation (anti-doublon).
        $open = WorkflowInstance::where('operation_type', $operationType->value)
            ->where('operation_ref', $operationRef)
            ->whereIn('status', [
                InstanceStatus::Pending->value,
                InstanceStatus::InProgress->value,
                InstanceStatus::CorrectionRequested->value,
            ])
            ->exists();

        if ($open) {
            throw new DomainException('INSTANCE_ALREADY_OPEN', 'Une instance est deja ouverte pour cette operation.');
        }

        return DB::transaction(function () use ($workflow, $institutionId, $operationType, $operationRef, $startedBy, $context, $actor, $correlationId) {
            $groups = $workflow->steps->pluck('step_group')->unique()->sort()->values();
            $firstGroup = $groups->first();

            $instance = WorkflowInstance::create([
                'workflow_id' => $workflow->id,
                'workflow_version' => $workflow->version,
                'institution_id' => $institutionId,
                'operation_type' => $operationType,
                'operation_ref' => $operationRef,
                'status' => InstanceStatus::InProgress,
                'current_step_group' => $firstGroup,
                'context' => $context,
                'started_by' => $startedBy,
            ]);

            $this->spawnGroupTasks($instance, $workflow, $firstGroup);

            $this->outbox->record(
                'workflow.instance_started',
                'workflow_instance',
                $instance->id,
                [
                    'instance_id' => $instance->id,
                    'workflow_id' => $workflow->id,
                    'workflow_version' => $workflow->version,
                    'institution_id' => $institutionId,
                    'operation_type' => $operationType->value,
                    'operation_ref' => $operationRef,
                    'started_by' => $startedBy,
                ],
                correlationId: $correlationId,
                institutionId: $institutionId,
                actor: $actor,
            );

            return $instance->load('tasks');
        });
    }

    /**
     * Decision sur une tache : approve / reject / request_correction.
     *
     * @param  array{type?: string, id?: string}|null  $actor
     * @return array{0: WorkflowTask, 1: WorkflowInstance}
     */
    public function decideTask(WorkflowTask $task, TaskDecision $decision, ?string $comment, string $decidedBy, bool $isDelegated = false, ?array $actor = null, ?string $correlationId = null): array
    {
        if (in_array($decision, [TaskDecision::Reject, TaskDecision::RequestCorrection], true) && trim((string) $comment) === '') {
            throw new DomainException('TASK_COMMENT_REQUIRED', 'Un motif est obligatoire pour un refus ou une demande de correction.');
        }

        return DB::transaction(function () use ($task, $decision, $comment, $decidedBy, $isDelegated, $actor, $correlationId) {
            $task = WorkflowTask::lockForUpdate()->findOrFail($task->id);
            $instance = WorkflowInstance::lockForUpdate()->findOrFail($task->instance_id);
            $workflow = $instance->workflow;

            if (! $instance->isOpen() || $instance->status === InstanceStatus::Pending) {
                throw new DomainException('INSTANCE_NOT_DECIDABLE', 'Cette instance n\'accepte plus de decision.');
            }

            if (! $task->isPending() || $task->step_group !== $instance->current_step_group) {
                throw new DomainException('TASK_NOT_ACTIONABLE', 'Cette tache n\'est pas en attente de decision.');
            }

            $task->status = match ($decision) {
                TaskDecision::Approve => TaskStatus::Approved,
                TaskDecision::Reject => TaskStatus::Rejected,
                TaskDecision::RequestCorrection => TaskStatus::CorrectionRequested,
            };
            $task->decided_by = $decidedBy;
            $task->decided_at = now();
            $task->decision_comment = $comment;
            $task->is_delegated = $isDelegated;
            $task->save();

            $this->outbox->record(
                'workflow.task_decided',
                'workflow_task',
                $task->id,
                [
                    'task_id' => $task->id,
                    'instance_id' => $instance->id,
                    'institution_id' => $instance->institution_id,
                    'operation_type' => $instance->operation_type->value,
                    'operation_ref' => $instance->operation_ref,
                    'step_name' => $task->step_name,
                    'decision' => $decision->value,
                    'is_delegated' => $isDelegated,
                    'comment' => $comment,
                ],
                correlationId: $correlationId,
                institutionId: $instance->institution_id,
                actor: $actor ?? ['type' => 'user', 'id' => $decidedBy],
            );

            return [$task, $this->advance($instance, $workflow, $decision, $decidedBy, $comment, $actor, $correlationId)];
        });
    }

    /**
     * Annule une instance ouverte (taches pendantes annulees).
     *
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function cancelInstance(WorkflowInstance $instance, string $motif, string $cancelledBy, ?array $actor = null, ?string $correlationId = null): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $motif, $cancelledBy, $actor, $correlationId) {
            $instance = WorkflowInstance::lockForUpdate()->findOrFail($instance->id);

            if (! $instance->isOpen()) {
                throw new DomainException('INSTANCE_NOT_CANCELLABLE', 'Instance deja cloturee.');
            }

            $instance->tasks()->where('status', TaskStatus::Pending->value)->update([
                'status' => TaskStatus::Cancelled->value,
                'decided_by' => $cancelledBy,
                'decided_at' => now(),
                'decision_comment' => $motif,
            ]);

            $instance->status = InstanceStatus::Cancelled;
            $instance->decided_outcome = 'cancelled';
            $instance->decided_by = $cancelledBy;
            $instance->decided_at = now();
            $instance->decision_comment = $motif;
            $instance->save();

            $this->recordCompleted($instance, 'cancelled', $cancelledBy, $motif, $actor, $correlationId);

            return $instance;
        });
    }

    private function advance(WorkflowInstance $instance, Workflow $workflow, TaskDecision $decision, string $decidedBy, ?string $comment, ?array $actor, ?string $correlationId): WorkflowInstance
    {
        $group = $instance->current_step_group;
        $groupTasks = $instance->tasks()->where('step_group', $group)->get();

        if ($decision === TaskDecision::Reject) {
            return $this->complete($instance, InstanceStatus::Rejected, 'rejected', $decidedBy, $comment, $actor, $correlationId);
        }

        if ($decision === TaskDecision::RequestCorrection) {
            // La correction renvoie l'operation au demandeur : l'instance
            // est marquee correction_requested (evenement instance_completed
            // avec outcome correction_requested pour le service metier).
            $instance->tasks()->where('step_group', $group)->where('status', TaskStatus::Pending->value)
                ->update(['status' => TaskStatus::Cancelled->value, 'decided_at' => now()]);

            return $this->complete($instance, InstanceStatus::CorrectionRequested, 'correction_requested', $decidedBy, $comment, $actor, $correlationId);
        }

        // approve : evaluation du groupe courant
        $pending = $groupTasks->where('status', TaskStatus::Pending);
        $approved = $groupTasks->where('status', TaskStatus::Approved);

        // Regle 'any' : si toutes les taches pendantes du groupe sont en
        // mode 'any' et qu'au moins une est approuvee, le groupe est satisfait.
        $allRemainingAny = $pending->every(fn ($t) => $t->mode === StepMode::Any);
        $anyApproved = $approved->isNotEmpty();
        $allApproved = $pending->isEmpty();

        $groupSatisfied = $allApproved || ($allRemainingAny && $anyApproved);

        if (! $groupSatisfied) {
            $instance->save();

            return $instance;
        }

        // Les taches 'any' restantes sont soldees par l'approbation collective.
        if ($allRemainingAny && $anyApproved) {
            $instance->tasks()->where('step_group', $group)->where('status', TaskStatus::Pending->value)
                ->update(['status' => TaskStatus::Approved->value, 'decided_by' => $decidedBy, 'decided_at' => now()]);
        }

        $nextGroup = $workflow->steps->pluck('step_group')->unique()->sort()->first(
            fn ($g) => $g > $group,
        );

        if ($nextGroup === null) {
            return $this->complete($instance, InstanceStatus::Approved, 'approved', $decidedBy, $comment, $actor, $correlationId);
        }

        $instance->current_step_group = $nextGroup;
        $instance->save();
        $this->spawnGroupTasks($instance, $workflow, $nextGroup);

        return $instance;
    }

    private function complete(WorkflowInstance $instance, InstanceStatus $status, string $outcome, string $decidedBy, ?string $comment, ?array $actor, ?string $correlationId): WorkflowInstance
    {
        $instance->status = $status;
        $instance->decided_outcome = $outcome;
        $instance->decided_by = $decidedBy;
        $instance->decided_at = now();
        $instance->decision_comment = $comment;
        $instance->save();

        $this->recordCompleted($instance, $outcome, $decidedBy, $comment, $actor, $correlationId);

        return $instance;
    }

    private function spawnGroupTasks(WorkflowInstance $instance, Workflow $workflow, int $group): void
    {
        $steps = $workflow->steps->where('step_group', $group);

        foreach ($steps as $step) {
            WorkflowTask::create([
                'instance_id' => $instance->id,
                'step_id' => $step->id,
                'step_group' => $step->step_group,
                'step_name' => $step->name,
                'assignee_type' => $step->assignee_type,
                'assignee_ref' => $step->assignee_ref,
                'mode' => $step->mode,
                'sla_hours' => $step->sla_hours,
                'sla_due_at' => $step->sla_hours !== null ? now()->addHours($step->sla_hours) : null,
                'status' => TaskStatus::Pending,
            ]);
        }
    }

    private function recordCompleted(WorkflowInstance $instance, string $outcome, string $decidedBy, ?string $comment, ?array $actor, ?string $correlationId): void
    {
        $this->outbox->record(
            'workflow.instance_completed',
            'workflow_instance',
            $instance->id,
            [
                'instance_id' => $instance->id,
                'workflow_id' => $instance->workflow_id,
                'institution_id' => $instance->institution_id,
                'operation_type' => $instance->operation_type->value,
                'operation_ref' => $instance->operation_ref,
                'outcome' => $outcome,
                'decided_by' => $decidedBy,
                'decided_at' => $instance->decided_at?->toIso8601String(),
                'decision_comment' => $comment,
            ],
            correlationId: $correlationId,
            institutionId: $instance->institution_id,
            actor: $actor,
        );
    }
}
