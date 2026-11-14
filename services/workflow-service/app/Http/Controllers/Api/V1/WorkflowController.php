<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\StoreWorkflowRequest;
use App\Http\Resources\WorkflowResource;
use App\Models\Workflow;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly WorkflowService $workflows) {}

    public function index(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = Workflow::visibleTo($claims['institution_id'])->with('steps')->orderBy('code');

        if ($domain = $request->query('domain')) {
            $query->where('domain', $domain);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            WorkflowResource::class,
        );
    }

    public function store(StoreWorkflowRequest $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $institutionId = $request->validated('institution_id');

        // institution_id NULL explicite = workflow national → droit central.
        // Sinon, forcer l'institution de l'appelant (pas d'usurpation).
        if ($institutionId === null && array_key_exists('institution_id', $request->validated())) {
            if (! in_array('workflow.templates.national', $claims['permissions'], true)) {
                abort(403);
            }
        } else {
            $institutionId = $institutionId ?? $claims['institution_id'];
        }

        $workflow = $this->workflows->createWorkflow(
            [
                'institution_id' => $institutionId,
                'parent_workflow_id' => $request->validated('parent_workflow_id'),
                'domain' => $request->validated('domain'),
                'code' => $request->validated('code'),
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'status' => $request->validated('status') ?? 'draft',
            ],
            $institutionId,
            $request->validated('steps'),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new WorkflowResource($workflow))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/workflows/{$workflow->id}")
            ->header('ETag', '"'.$workflow->updated_at->timestamp.'"');
    }

    public function show(Workflow $workflow): WorkflowResource
    {
        return new WorkflowResource($workflow->load('steps'));
    }
}
