<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CustodyRole;
use App\Enums\HolderType;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\MotifRequest;
use App\Http\Requests\StoreCustodyRequest;
use App\Http\Resources\CustodyResource;
use App\Models\CustodyRecord;
use App\Services\MovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustodyController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly MovementService $movements) {}

    public function index(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = CustodyRecord::query()->orderByDesc('valid_from');

        if ($assetId = $request->query('asset_id')) {
            $query->where('asset_id', $assetId);
        }
        if ($holderType = $request->query('holder_type')) {
            $query->where('holder_type', $holderType);
        }
        if ($holderRef = $request->query('holder_ref')) {
            $query->where('holder_ref', $holderRef);
        }
        if ($role = $request->query('custody_role')) {
            $query->where('custody_role', $role);
        }
        if ($institutionId = $request->query('institution_id') ?? $claims['institution_id']) {
            $query->where('institution_id', $institutionId);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            CustodyResource::class,
        );
    }

    public function store(StoreCustodyRequest $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);
        $data = $request->validated();

        $institutionId = $data['institution_id'] ?? $claims['institution_id'];
        if ($institutionId === null) {
            // Affectation au niveau national : permission centrale requise.
            abort_unless(in_array('movement.custody.national', $claims['permissions'], true), 403);
        }

        $custody = $this->movements->assignCustody(
            $data['asset_id'],
            (string) $institutionId,
            HolderType::from($data['holder_type']),
            $data['holder_ref'],
            CustodyRole::from($data['custody_role']),
            $claims['sub'],
            $data['valid_from'] ?? null,
            null,
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
            $data['motif'] ?? null,
        );

        return (new CustodyResource($custody))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/custody/{$custody->id}");
    }

    public function close(MotifRequest $request, CustodyRecord $custody): CustodyResource
    {
        $claims = AuthenticateUser::claims($request);

        return new CustodyResource($this->movements->closeCustody(
            $custody,
            $request->validated('motif'),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }
}
