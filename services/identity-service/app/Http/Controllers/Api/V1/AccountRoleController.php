<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateFunctionalAccount;
use App\Http\Requests\AssignRoleRequest;
use App\Http\Resources\AccountRoleResource;
use App\Models\AccountRole;
use App\Models\FunctionalAccount;
use App\Models\Role;
use App\Services\AccountRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountRoleController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly AccountRoleService $service) {}

    public function index(Request $request, FunctionalAccount $account): JsonResponse
    {
        $query = AccountRole::where('functional_account_id', $account->id)
            ->orderBy('granted_at');

        if ($request->boolean('active_only')) {
            $query->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            AccountRoleResource::class,
        );
    }

    public function store(AssignRoleRequest $request, FunctionalAccount $account): JsonResponse
    {
        /** @var FunctionalAccount $actor */
        $actor = $request->attributes->get(AuthenticateFunctionalAccount::ACCOUNT_ATTRIBUTE);

        $role = Role::findOrFail($request->validated('role_id'));
        $this->service->assertCanGrantPrivileged($actor, $account, $role);

        $assignment = $this->service->assign(
            $account,
            $role,
            $request->validated('institution_id'),
            $request->validated('organizational_unit_id'),
            $actor?->id,
            $request->validated('expires_at') !== null ? \Illuminate\Support\Carbon::parse($request->validated('expires_at')) : null,
            $request->header('X-Correlation-ID'),
        );

        return (new AccountRoleResource($assignment))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, FunctionalAccount $account, AccountRole $assignment): JsonResponse
    {
        if ($assignment->functional_account_id !== $account->id) {
            throw new DomainException('ASSIGNMENT_NOT_FOUND', 'Attribution introuvable pour ce compte.');
        }

        /** @var FunctionalAccount $actor */
        $actor = $request->attributes->get(AuthenticateFunctionalAccount::ACCOUNT_ATTRIBUTE);

        $this->service->revoke($assignment, $actor?->id ?? '', $request->header('X-Correlation-ID'));

        return response()->json(null, 204);
    }
}
