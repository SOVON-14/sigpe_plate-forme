<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SharedRightStatus;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\IncidentReportRequest;
use App\Http\Requests\MotifRequest;
use App\Http\Requests\OperationConfirmationRequest;
use App\Http\Requests\StoreOperationRequest;
use App\Http\Requests\StoreSharedRightRequest;
use App\Http\Requests\SubmitOperationRequest;
use App\Http\Resources\HistoryEntryResource;
use App\Http\Resources\OperationResource;
use App\Http\Resources\SharedRightResource;
use App\Models\CustodyRecord;
use App\Models\MovementHistory;
use App\Models\MovementOperation;
use App\Models\SharedRight;
use App\Services\MovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly MovementService $movements) {}

    // ---------------- Operations ----------------

    public function index(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = MovementOperation::forInstitution($claims['institution_id'])
            ->with('assets')
            ->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($assetId = $request->query('asset_id')) {
            $query->whereHas('assets', fn ($q) => $q->where('asset_id', $assetId));
        }
        if ($institutionId = $request->query('institution_id')) {
            $query->where(fn ($q) => $q->where('institution_id', $institutionId)->orWhere('destination_institution_id', $institutionId));
        }
        if ($ref = $request->query('operation_ref')) {
            $query->where('operation_ref', $ref);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            OperationResource::class,
        );
    }

    public function store(StoreOperationRequest $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);
        $data = $request->validated();

        // L'institution porteuse = perimetre de l'appelant ; le
        // destinataire peut etre different (transfert, pret).
        $institutionId = $claims['institution_id'];
        if ($institutionId === null) {
            abort_unless(in_array('movement.operations.national', $claims['permissions'], true), 403);
            $institutionId = $data['institution_id'] ?? null;
        }
        if ($institutionId === null) {
            abort(422, 'institution_id requis pour un operateur national.');
        }

        $operation = $this->movements->createOperation(
            $data,
            (string) $institutionId,
            $data['asset_ids'],
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new OperationResource($operation))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/operations/{$operation->id}")
            ->header('ETag', '"'.$operation->updated_at->timestamp.'"');
    }

    public function show(MovementOperation $operation): OperationResource
    {
        return new OperationResource($operation->load(['assets', 'custodies']));
    }

    public function submit(SubmitOperationRequest $request, MovementOperation $operation): OperationResource
    {
        $claims = AuthenticateUser::claims($request);

        return new OperationResource($this->movements->submitToWorkflow(
            $operation,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        )->fresh('assets'));
    }

    public function confirmDeparture(OperationConfirmationRequest $request, MovementOperation $operation): OperationResource
    {
        $claims = AuthenticateUser::claims($request);

        return new OperationResource($this->movements->confirmDeparture(
            $operation,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        )->fresh('assets'));
    }

    public function confirmReception(OperationConfirmationRequest $request, MovementOperation $operation): OperationResource
    {
        $claims = AuthenticateUser::claims($request);

        return new OperationResource($this->movements->confirmReception(
            $operation,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        )->fresh('assets'));
    }

    public function returnLoan(OperationConfirmationRequest $request, MovementOperation $operation): OperationResource
    {
        $claims = AuthenticateUser::claims($request);

        return new OperationResource($this->movements->returnLoan(
            $operation,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        )->fresh('assets'));
    }

    public function reportIncident(IncidentReportRequest $request, MovementOperation $operation): OperationResource
    {
        $claims = AuthenticateUser::claims($request);

        return new OperationResource($this->movements->reportIncident(
            $operation,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        )->fresh('assets'));
    }

    public function cancel(MotifRequest $request, MovementOperation $operation): OperationResource
    {
        $claims = AuthenticateUser::claims($request);

        return new OperationResource($this->movements->cancelOperation(
            $operation,
            $request->validated('motif'),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        )->fresh('assets'));
    }

    public function history(Request $request, MovementOperation $operation): JsonResponse
    {
        $query = MovementHistory::where('entity_type', 'operation')
            ->where('entity_id', $operation->id)
            ->orderBy('occurred_at');

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            HistoryEntryResource::class,
        );
    }

    public function assetMovements(Request $request, string $assetId): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        // Gardes du bien (toutes, pas seulement courantes)
        $custodyQuery = CustodyRecord::where('asset_id', $assetId);
        if ($claims['institution_id'] !== null) {
            $custodyQuery->where('institution_id', $claims['institution_id']);
        }
        $custodies = $custodyQuery->get()
            ->map(fn ($c) => [
                'kind' => 'custody',
                'occurred_at' => $c->valid_from->toIso8601String(),
                'operation_id' => $c->source_operation_id,
                'holder_type' => $c->holder_type->value,
                'holder_ref' => $c->holder_ref,
                'custody_role' => $c->custody_role->value,
                'motif' => null,
            ]);

        // Operations impliquant le bien
        $operations = MovementOperation::forInstitution($claims['institution_id'])
            ->whereHas('assets', fn ($q) => $q->where('asset_id', $assetId))
            ->with('assets')
            ->get()
            ->map(fn ($o) => [
                'kind' => 'operation',
                'occurred_at' => $o->created_at->toIso8601String(),
                'operation_id' => $o->id,
                'operation_type' => $o->type->value,
                'status' => $o->status->value,
                'holder_type' => null,
                'holder_ref' => null,
                'custody_role' => null,
                'motif' => $o->motif,
            ]);

        $entries = $custodies->concat($operations)
            ->sortByDesc('occurred_at')
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min((int) $request->query('per_page', 20), 100);

        return response()->json([
            'data' => $entries->forPage($page, $perPage)->values(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $entries->count(),
                'last_page' => max(1, (int) ceil($entries->count() / $perPage)),
            ],
        ]);
    }

    // ---------------- Biens partages ----------------

    public function sharedRightsIndex(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = SharedRight::visibleTo($claims['institution_id'])->orderByDesc('created_at');

        if ($assetId = $request->query('asset_id')) {
            $query->where('asset_id', $assetId);
        }
        if ($beneficiary = $request->query('beneficiary_institution_id')) {
            $query->where('beneficiary_institution_id', $beneficiary);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            SharedRightResource::class,
        );
    }

    public function sharedRightsStore(StoreSharedRightRequest $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $right = $this->movements->createSharedRight(
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new SharedRightResource($right))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/shared-rights/{$right->id}");
    }

    public function sharedRightsSuspend(MotifRequest $request, SharedRight $right): SharedRightResource
    {
        $claims = AuthenticateUser::claims($request);

        return new SharedRightResource($this->movements->transitionSharedRight(
            $right,
            SharedRightStatus::Suspended,
            $request->validated('motif'),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function sharedRightsTerminate(MotifRequest $request, SharedRight $right): SharedRightResource
    {
        $claims = AuthenticateUser::claims($request);

        return new SharedRightResource($this->movements->transitionSharedRight(
            $right,
            SharedRightStatus::Terminated,
            $request->validated('motif'),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }
}
