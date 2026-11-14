<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\StoreInterventionPlan;
use App\Http\Resources\InterventionPlanResource;
use App\Models\InterventionPlan;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterventionPlanController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function index(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = InterventionPlan::forInstitution($claims['institution_id'])
            ->orderByDesc('created_at');

        if ($assetId = $request->query('asset_id')) {
            $query->where('asset_id', $assetId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            InterventionPlanResource::class,
        );
    }

    public function store(StoreInterventionPlan $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $institutionId = $claims['institution_id'];
        if ($institutionId === null) {
            abort_unless(in_array('maintenance.plans.national', $claims['permissions'], true), 403);
            $institutionId = $request->input('institution_id');
        }
        if ($institutionId === null) {
            abort(422, 'institution_id requis pour un operateur national.');
        }

        $plan = $this->maintenance->createPlan(
            $request->validated(),
            (string) $institutionId,
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new InterventionPlanResource($plan))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/intervention-plans/{$plan->id}");
    }

    public function show(InterventionPlan $plan): InterventionPlanResource
    {
        return new InterventionPlanResource($plan);
    }

    public function execute(Request $request, InterventionPlan $plan): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $intervention = $this->maintenance->executePlan(
            $plan,
            $request->input('planned_at'),
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return response()->json([
            'data' => [
                'plan' => new InterventionPlanResource($plan->fresh()),
                'intervention_id' => $intervention->id,
            ],
        ]);
    }
}
