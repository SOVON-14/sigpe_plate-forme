<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InstitutionStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\ChangeInstitutionStatusRequest;
use App\Http\Requests\StoreInstitutionRequest;
use App\Http\Resources\InstitutionResource;
use App\Models\Institution;
use App\Services\InstitutionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstitutionController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly InstitutionService $institutions) {}

    public function index(Request $request): JsonResponse
    {
        $query = Institution::query()->orderBy('code');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($q = $request->query('q')) {
            $query->where(fn (Builder $b) => $b
                ->where('name', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%"));
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            InstitutionResource::class,
        );
    }

    public function store(StoreInstitutionRequest $request): JsonResponse
    {
        $actor = AuthenticateUser::claims($request)['sub'];

        try {
            $institution = $this->institutions->create(
                $request->validated('code'),
                $request->validated('name'),
                $request->validated('type') ?? 'ministere',
                $request->validated('description'),
                $actor,
                $request->header('X-Correlation-ID'),
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw new DomainException('INSTITUTION_DUPLICATE', 'Une institution existe déjà avec ce code.');
        }

        return (new InstitutionResource($institution))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/institutions/{$institution->id}")
            ->header('ETag', '"' . $institution->updated_at->timestamp . '"');
    }

    public function show(Institution $institution): InstitutionResource
    {
        return new InstitutionResource($institution);
    }

    public function changeStatus(ChangeInstitutionStatusRequest $request, Institution $institution): InstitutionResource
    {
        $claims = AuthenticateUser::claims($request);

        $institution = $this->institutions->changeStatus(
            $institution,
            InstitutionStatus::from($request->validated('status')),
            $claims['sub'],
            $request->validated('motif'),
            $request->header('X-Correlation-ID'),
        );

        return new InstitutionResource($institution);
    }
}
