<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateFunctionalAccount;
use App\Http\Requests\ChangeAccountStatusRequest;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Resources\FunctionalAccountResource;
use App\Models\FunctionalAccount;
use App\Services\FunctionalAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly FunctionalAccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $query = FunctionalAccount::query()->orderBy('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($institutionId = $request->query('institution_id')) {
            $query->whereHas('accountRoles', fn (Builder $q) => $q->where('institution_id', $institutionId));
        }
        if ($q = $request->query('q')) {
            $query->where(fn (Builder $b) => $b
                ->where('display_name', 'like', "%{$q}%")
                ->orWhere('keycloak_sub', 'like', "%{$q}%"));
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            FunctionalAccountResource::class,
        );
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        /** @var FunctionalAccount $actor */
        $actor = $request->attributes->get(AuthenticateFunctionalAccount::ACCOUNT_ATTRIBUTE);

        try {
            $account = $this->accounts->create(
                $request->validated('keycloak_sub'),
                $request->validated('display_name'),
                $actor?->id,
                $request->header('X-Correlation-ID'),
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw new DomainException('ACCOUNT_DUPLICATE', 'Un compte existe déjà pour ce keycloak_sub.');
        }

        return (new FunctionalAccountResource($account))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/accounts/{$account->id}")
            ->header('ETag', '"' . $account->updated_at->timestamp . '"');
    }

    public function show(FunctionalAccount $account): FunctionalAccountResource
    {
        return new FunctionalAccountResource($account);
    }

    public function changeStatus(ChangeAccountStatusRequest $request, FunctionalAccount $account): FunctionalAccountResource
    {
        /** @var FunctionalAccount $actor */
        $actor = $request->attributes->get(AuthenticateFunctionalAccount::ACCOUNT_ATTRIBUTE);

        $account = $this->accounts->changeStatus(
            $account,
            AccountStatus::from($request->validated('status')),
            $actor?->id,
            $request->header('X-Correlation-ID'),
        );

        return new FunctionalAccountResource($account);
    }
}
