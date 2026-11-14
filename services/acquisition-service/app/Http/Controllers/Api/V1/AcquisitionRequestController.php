<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\MotifRequest;
use App\Http\Requests\OfferSelectionRequest;
use App\Http\Requests\OrderRequest;
use App\Http\Requests\StoreAcquisitionRequest;
use App\Http\Requests\SubmitAcquisitionRequest;
use App\Http\Resources\AcquisitionRequestResource;
use App\Http\Resources\HistoryEntryResource;
use App\Models\AcquisitionHistory;
use App\Models\AcquisitionRequest;
use App\Services\AcquisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcquisitionRequestController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly AcquisitionService $acquisitions) {}

    public function index(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = AcquisitionRequest::forInstitution($claims['institution_id'])
            ->with('items')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($unitId = $request->query('organizational_unit_id')) {
            $query->where('organizational_unit_id', $unitId);
        }
        if ($q = $request->query('q')) {
            $query->where(fn ($qb) => $qb->where('reference', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%"));
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            AcquisitionRequestResource::class,
        );
    }

    public function store(StoreAcquisitionRequest $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $institutionId = $claims['institution_id'];
        if ($institutionId === null) {
            abort_unless(in_array('acquisition.requests.national', $claims['permissions'], true), 403);
            $institutionId = $request->input('institution_id');
        }
        if ($institutionId === null) {
            abort(422, 'institution_id requis pour un operateur national.');
        }

        $acquisition = $this->acquisitions->createRequest(
            $request->validated(),
            (string) $institutionId,
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new AcquisitionRequestResource($acquisition))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/acquisition-requests/{$acquisition->id}");
    }

    public function show(AcquisitionRequest $acquisition): AcquisitionRequestResource
    {
        return new AcquisitionRequestResource(
            $acquisition->load(['items', 'deliveries.receptions.lines'])
        );
    }

    public function submit(SubmitAcquisitionRequest $request, AcquisitionRequest $acquisition): AcquisitionRequestResource
    {
        $claims = AuthenticateUser::claims($request);

        return new AcquisitionRequestResource($this->acquisitions->submitToWorkflow(
            $acquisition,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function select(OfferSelectionRequest $request, AcquisitionRequest $acquisition): AcquisitionRequestResource
    {
        $claims = AuthenticateUser::claims($request);

        return new AcquisitionRequestResource($this->acquisitions->selectOffer(
            $acquisition,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function order(OrderRequest $request, AcquisitionRequest $acquisition): AcquisitionRequestResource
    {
        $claims = AuthenticateUser::claims($request);

        return new AcquisitionRequestResource($this->acquisitions->placeOrder(
            $acquisition,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function close(Request $request, AcquisitionRequest $acquisition): AcquisitionRequestResource
    {
        $claims = AuthenticateUser::claims($request);

        return new AcquisitionRequestResource($this->acquisitions->closeRequest(
            $acquisition,
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function cancel(MotifRequest $request, AcquisitionRequest $acquisition): AcquisitionRequestResource
    {
        $claims = AuthenticateUser::claims($request);

        return new AcquisitionRequestResource($this->acquisitions->cancelRequest(
            $acquisition,
            $request->validated('motif'),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function history(Request $request, AcquisitionRequest $acquisition): JsonResponse
    {
        return $this->paginated(
            AcquisitionHistory::where('entity_type', 'request')
                ->where('entity_id', $acquisition->id)
                ->orderBy('occurred_at')
                ->paginate(min((int) $request->query('per_page', 20), 100)),
            HistoryEntryResource::class,
        );
    }
}
