<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OperationDomain;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\CancelInstanceRequest;
use App\Http\Requests\StartInstanceRequest;
use App\Http\Resources\InstanceResource;
use App\Http\Resources\TaskResource;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstanceController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly WorkflowService $workflows) {}

    public function index(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = WorkflowInstance::forInstitution($claims['institution_id'])
            ->with('tasks')
            ->orderByDesc('created_at');

        if ($type = $request->query('operation_type')) {
            $query->where('operation_type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($ref = $request->query('operation_ref')) {
            $query->where('operation_ref', $ref);
        }
        if ($institutionId = $request->query('institution_id')) {
            $query->where('institution_id', $institutionId);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            InstanceResource::class,
        );
    }

    public function store(StartInstanceRequest $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $workflow = Workflow::findOrFail($request->validated('workflow_id'));

        $instance = $this->workflows->startInstance(
            $workflow,
            $request->validated('institution_id'),
            OperationDomain::from($request->validated('operation_type')),
            $request->validated('operation_ref'),
            $claims['sub'],
            $request->validated('context'),
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new InstanceResource($instance->load('tasks')))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/workflow-instances/{$instance->id}")
            ->header('ETag', '"'.$instance->updated_at->timestamp.'"');
    }

    public function show(WorkflowInstance $instance): InstanceResource
    {
        return new InstanceResource($instance->load('tasks'));
    }

    public function cancel(CancelInstanceRequest $request, WorkflowInstance $instance): InstanceResource
    {
        $claims = AuthenticateUser::claims($request);

        return new InstanceResource($this->workflows->cancelInstance(
            $instance,
            $request->validated('motif'),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        )->fresh('tasks'));
    }

    public function tasks(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $query = $instance->tasks()->orderBy('step_group')->orderBy('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => TaskResource::collection($query->get())->resolve(),
        ]);
    }
}
