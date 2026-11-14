<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\CompleteIntervention;
use App\Http\Requests\MotifRequest;
use App\Http\Requests\StoreIntervention;
use App\Http\Resources\HistoryEntryResource;
use App\Http\Resources\InterventionResource;
use App\Models\Intervention;
use App\Models\MaintenanceHistory;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterventionController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function index(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = Intervention::forInstitution($claims['institution_id'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($assetId = $request->query('asset_id')) {
            $query->where('asset_id', $assetId);
        }
        if ($requestId = $request->query('request_id')) {
            $query->where('request_id', $requestId);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            InterventionResource::class,
        );
    }

    public function store(StoreIntervention $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $intervention = $this->maintenance->createIntervention(
            $request->validated(),
            (string) $claims['institution_id'],
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new InterventionResource($intervention))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/interventions/{$intervention->id}");
    }

    public function show(Intervention $intervention): InterventionResource
    {
        return new InterventionResource($intervention);
    }

    public function start(Request $request, Intervention $intervention): InterventionResource
    {
        $claims = AuthenticateUser::claims($request);

        return new InterventionResource($this->maintenance->startIntervention(
            $intervention,
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function complete(CompleteIntervention $request, Intervention $intervention): InterventionResource
    {
        $claims = AuthenticateUser::claims($request);

        return new InterventionResource($this->maintenance->completeIntervention(
            $intervention,
            $request->validated(),
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function interrupt(MotifRequest $request, Intervention $intervention): InterventionResource
    {
        $claims = AuthenticateUser::claims($request);

        return new InterventionResource($this->maintenance->interruptIntervention(
            $intervention,
            $request->validated('motif'),
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function cancel(MotifRequest $request, Intervention $intervention): InterventionResource
    {
        $claims = AuthenticateUser::claims($request);

        return new InterventionResource($this->maintenance->cancelIntervention(
            $intervention,
            $request->validated('motif'),
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function history(Request $request, Intervention $intervention): JsonResponse
    {
        return $this->paginated(
            MaintenanceHistory::where('entity_type', 'intervention')
                ->where('entity_id', $intervention->id)
                ->orderBy('occurred_at')
                ->paginate(min((int) $request->query('per_page', 20), 100)),
            HistoryEntryResource::class,
        );
    }
}
