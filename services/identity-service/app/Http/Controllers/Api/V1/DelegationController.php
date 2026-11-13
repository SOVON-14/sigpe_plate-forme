<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DelegationStatus;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateFunctionalAccount;
use App\Http\Requests\StoreDelegationRequest;
use App\Http\Resources\DelegationResource;
use App\Models\Delegation;
use App\Models\FunctionalAccount;
use App\Models\Role;
use App\Services\DelegationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DelegationController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly DelegationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Delegation::orderByDesc('created_at');

        if ($delegator = $request->query('delegator_id')) {
            $query->where('delegator_functional_account_id', $delegator);
        }
        if ($beneficiary = $request->query('beneficiary_id')) {
            $query->where('beneficiary_functional_account_id', $beneficiary);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            DelegationResource::class,
        );
    }

    public function store(StoreDelegationRequest $request): JsonResponse
    {
        /** @var FunctionalAccount $delegator */
        $delegator = $request->attributes->get(AuthenticateFunctionalAccount::ACCOUNT_ATTRIBUTE);

        $beneficiary = FunctionalAccount::findOrFail($request->validated('beneficiary_functional_account_id'));
        $role = $request->validated('role_id') !== null ? Role::find($request->validated('role_id')) : null;

        $delegation = $this->service->create(
            $delegator,
            $beneficiary,
            $role,
            Carbon::parse($request->validated('valid_from')),
            Carbon::parse($request->validated('valid_to')),
            $request->validated('institution_id'),
            $request->validated('organizational_unit_id'),
            $request->validated('motif'),
            $request->header('X-Correlation-ID'),
        );

        return (new DelegationResource($delegation))
            ->response()
            ->setStatusCode(201);
    }

    public function revoke(Request $request, Delegation $delegation): DelegationResource
    {
        /** @var FunctionalAccount $actor */
        $actor = $request->attributes->get(AuthenticateFunctionalAccount::ACCOUNT_ATTRIBUTE);

        $delegation = $this->service->revoke(
            $delegation,
            $actor?->id ?? '',
            $request->header('X-Correlation-ID'),
        );

        return new DelegationResource($delegation);
    }
}
