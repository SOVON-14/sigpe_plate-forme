<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskDecision;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\TaskDecisionRequest;
use App\Http\Resources\InstanceResource;
use App\Http\Resources\TaskResource;
use App\Models\WorkflowTask;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;

class TaskController extends Controller
{
    public function __construct(private readonly WorkflowService $workflows) {}

    public function show(WorkflowTask $task): TaskResource
    {
        return new TaskResource($task);
    }

    public function decide(TaskDecisionRequest $request, WorkflowTask $task): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        // Seul l'assigne (compte direct) ou un approbateur habilite
        // (role/position via permission workflow.tasks.decide) peut decider.
        // Delegation : claim 'delegations' du jeton (Identity) — une
        // delegation active couvrant l'assignataire autorise la decision.
        $direct = $task->canBeDecidedBy($claims['sub'], $claims['permissions']);
        $delegated = ! $direct && $this->hasActiveDelegation($claims, $task);

        if (! $direct && ! $delegated) {
            throw new DomainException('TASK_NOT_ASSIGNEE', 'Vous n\'etes pas l\'assignataire de cette tache.');
        }

        [$task, $instance] = $this->workflows->decideTask(
            $task,
            TaskDecision::from($request->validated('decision')),
            $request->validated('comment'),
            $claims['sub'],
            $delegated,
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return response()->json([
            'data' => [
                'task' => (new TaskResource($task))->resolve(),
                'instance' => (new InstanceResource($instance->fresh('tasks')))->resolve(),
            ],
        ]);
    }

    /**
     * Verifie si le jeton porte une delegation active couvrant
     * l'assignataire de la tache (claim 'delegations' injecte par Identity).
     *
     * @param  array<string, mixed>  $claims
     */
    private function hasActiveDelegation(array $claims, WorkflowTask $task): bool
    {
        $delegations = $claims['delegations'] ?? [];
        if (! is_array($delegations)) {
            return false;
        }

        foreach ($delegations as $delegation) {
            if (($delegation['status'] ?? null) !== 'active') {
                continue;
            }
            // La delegation doit couvrir le role/position de l'assignataire
            // ou l'account direct.
            if (($delegation['delegate_account_id'] ?? null) === $claims['sub']
                && in_array($task->assignee_ref, $delegation['scope'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
