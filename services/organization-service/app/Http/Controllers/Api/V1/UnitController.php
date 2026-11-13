<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UnitStatus;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\ChangeUnitStatusRequest;
use App\Http\Requests\MoveUnitRequest;
use App\Http\Requests\RenameUnitRequest;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Resources\OrganizationalUnitResource;
use App\Models\Institution;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitType;
use App\Services\OrganizationalUnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly OrganizationalUnitService $units) {}

    public function index(Request $request, Institution $institution): JsonResponse
    {
        $query = OrganizationalUnit::where('institution_id', $institution->id)
            ->orderBy('code');

        if ($parentId = $request->query('parent_id')) {
            $query->where('parent_id', $parentId);
        } elseif ($request->has('parent_id')) {
            $query->whereNull('parent_id');
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 100), 500)),
            OrganizationalUnitResource::class,
        );
    }

    public function store(StoreUnitRequest $request, Institution $institution): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $type = OrganizationalUnitType::find($request->validated('unit_type_id'));
        $parent = $request->validated('parent_id') !== null
            ? OrganizationalUnit::where('institution_id', $institution->id)->findOrFail($request->validated('parent_id'))
            : null;

        $unit = $this->units->create(
            $institution,
            $type ?? throw new \App\Exceptions\DomainException('UNIT_TYPE_NOT_FOUND', 'Type d\'unité introuvable.'),
            $request->validated('code'),
            $request->validated('name'),
            $parent,
            $claims['sub'],
            $request->header('X-Correlation-ID'),
        );

        return (new OrganizationalUnitResource($unit))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/units/{$unit->id}")
            ->header('ETag', '"' . $unit->updated_at->timestamp . '"');
    }

    public function show(OrganizationalUnit $unit): OrganizationalUnitResource
    {
        return new OrganizationalUnitResource($unit);
    }

    public function rename(RenameUnitRequest $request, OrganizationalUnit $unit): OrganizationalUnitResource
    {
        $claims = AuthenticateUser::claims($request);

        return new OrganizationalUnitResource($this->units->rename(
            $unit,
            $request->validated('name'),
            $claims['sub'],
            $request->validated('motif'),
            $request->header('X-Correlation-ID'),
        ));
    }

    public function move(MoveUnitRequest $request, OrganizationalUnit $unit): OrganizationalUnitResource
    {
        $claims = AuthenticateUser::claims($request);

        $newParent = null;
        if ($request->validated('parent_id') !== null) {
            $newParent = OrganizationalUnit::where('institution_id', $unit->institution_id)
                ->findOrFail($request->validated('parent_id'));
        }

        return new OrganizationalUnitResource($this->units->move(
            $unit,
            $newParent,
            $claims['sub'],
            $request->validated('motif'),
            $request->header('X-Correlation-ID'),
        ));
    }

    public function changeStatus(ChangeUnitStatusRequest $request, OrganizationalUnit $unit): OrganizationalUnitResource
    {
        $claims = AuthenticateUser::claims($request);

        return new OrganizationalUnitResource($this->units->changeStatus(
            $unit,
            UnitStatus::from($request->validated('status')),
            $claims['sub'],
            $request->validated('motif'),
            $request->header('X-Correlation-ID'),
        ));
    }
}
