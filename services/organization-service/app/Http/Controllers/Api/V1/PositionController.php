<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\AssignResponsibleRequest;
use App\Http\Requests\SetUnitHeadRequest;
use App\Http\Requests\StorePositionRequest;
use App\Http\Resources\OrganizationalUnitResource;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use App\Models\OrganizationalUnit;
use App\Services\PositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function __construct(private readonly PositionService $positions) {}

    public function index(Request $request, OrganizationalUnit $unit): JsonResponse
    {
        return response()->json([
            'data' => PositionResource::collection(
                $unit->positions()->orderBy('code')->get()
            )->resolve(),
        ]);
    }

    public function store(StorePositionRequest $request, OrganizationalUnit $unit): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $position = $this->positions->create(
            $unit,
            $request->validated('code'),
            $request->validated('title'),
            $claims['sub'],
            $request->header('X-Correlation-ID'),
        );

        return (new PositionResource($position))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/positions/{$position->id}");
    }

    public function assignResponsible(AssignResponsibleRequest $request, Position $position): PositionResource
    {
        $claims = AuthenticateUser::claims($request);

        return new PositionResource($this->positions->assignResponsible(
            $position,
            $request->validated('functional_account_id'),
            $claims['sub'],
            $request->validated('motif'),
            $request->header('X-Correlation-ID'),
        ));
    }

    public function revokeResponsible(Request $request, Position $position): PositionResource
    {
        $claims = AuthenticateUser::claims($request);

        return new PositionResource($this->positions->revokeResponsible(
            $position,
            $claims['sub'],
            null,
            $request->header('X-Correlation-ID'),
        ));
    }

    public function setUnitHead(SetUnitHeadRequest $request, OrganizationalUnit $unit): OrganizationalUnitResource
    {
        $claims = AuthenticateUser::claims($request);

        $position = Position::findOrFail($request->validated('position_id'));

        return new OrganizationalUnitResource($this->positions->setUnitHead(
            $unit,
            $position,
            $claims['sub'],
            $request->header('X-Correlation-ID'),
        ));
    }
}
