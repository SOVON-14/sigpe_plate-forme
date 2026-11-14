<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\MotifRequest;
use App\Http\Requests\StoreMaintenanceRequest;
use App\Http\Requests\SubmitRequest;
use App\Http\Resources\HistoryEntryResource;
use App\Http\Resources\MaintenanceRequestResource;
use App\Models\MaintenanceHistory;
use App\Models\MaintenanceRequest;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceRequestController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function index(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = MaintenanceRequest::forInstitution($claims['institution_id'])
            ->with('interventions')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($assetId = $request->query('asset_id')) {
            $query->where('asset_id', $assetId);
        }
        if ($q = $request->query('q')) {
            $query->where(fn ($qb) => $qb->where('reference', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"));
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            MaintenanceRequestResource::class,
        );
    }

    public function store(StoreMaintenanceRequest $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $institutionId = $claims['institution_id'];
        if ($institutionId === null) {
            abort_unless(in_array('maintenance.requests.national', $claims['permissions'], true), 403);
            $institutionId = $request->input('institution_id');
        }
        if ($institutionId === null) {
            abort(422, 'institution_id requis pour un operateur national.');
        }

        $maintenanceRequest = $this->maintenance->createRequest(
            $request->validated(),
            (string) $institutionId,
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new MaintenanceRequestResource($maintenanceRequest))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/maintenance-requests/{$maintenanceRequest->id}");
    }

    public function show(MaintenanceRequest $maintenanceRequest): MaintenanceRequestResource
    {
        return new MaintenanceRequestResource($maintenanceRequest->load('interventions'));
    }

    public function submit(SubmitRequest $request, MaintenanceRequest $maintenanceRequest): MaintenanceRequestResource
    {
        $claims = AuthenticateUser::claims($request);

        return new MaintenanceRequestResource($this->maintenance->submitToWorkflow(
            $maintenanceRequest,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function cancel(MotifRequest $request, MaintenanceRequest $maintenanceRequest): MaintenanceRequestResource
    {
        $claims = AuthenticateUser::claims($request);

        return new MaintenanceRequestResource($this->maintenance->cancelRequest(
            $maintenanceRequest,
            $request->validated('motif'),
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function history(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        return $this->paginated(
            MaintenanceHistory::where('entity_type', 'request')
                ->where('entity_id', $maintenanceRequest->id)
                ->orderBy('occurred_at')
                ->paginate(min((int) $request->query('per_page', 20), 100)),
            HistoryEntryResource::class,
        );
    }
}
