<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\StoreUnitTypeRequest;
use App\Http\Resources\UnitTypeResource;
use App\Models\Institution;
use App\Models\OrganizationalUnitType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitTypeController extends Controller
{
    public function index(Request $request, Institution $institution): JsonResponse
    {
        $query = OrganizationalUnitType::where(fn (Builder $q) => $q
            ->whereNull('institution_id')
            ->orWhere('institution_id', $institution->id))
            ->orderBy('code');

        return response()->json([
            'data' => UnitTypeResource::collection($query->get())->resolve(),
        ]);
    }

    public function store(StoreUnitTypeRequest $request, Institution $institution): JsonResponse
    {
        $type = OrganizationalUnitType::create([
            'institution_id' => $institution->id,
            'code' => $request->validated('code'),
            'label' => $request->validated('label'),
            'is_system' => false,
        ]);

        return (new UnitTypeResource($type))
            ->response()
            ->setStatusCode(201);
    }
}
