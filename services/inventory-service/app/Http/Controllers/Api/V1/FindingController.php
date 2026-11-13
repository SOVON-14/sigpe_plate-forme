<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\VerifyFindingRequest;
use App\Http\Resources\FindingResource;
use App\Models\InventoryFinding;
use App\Services\FindingService;

class FindingController extends Controller
{
    public function __construct(private readonly FindingService $findings) {}

    public function show(InventoryFinding $finding): FindingResource
    {
        return new FindingResource($finding);
    }

    public function verify(VerifyFindingRequest $request, InventoryFinding $finding): FindingResource
    {
        $claims = AuthenticateUser::claims($request);

        return new FindingResource($this->findings->verify(
            $finding,
            $request->validated('decision'),
            $request->validated('notes'),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        ));
    }
}
