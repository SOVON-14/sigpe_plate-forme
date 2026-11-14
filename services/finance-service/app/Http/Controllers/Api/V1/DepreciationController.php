<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\StoreDepreciation;
use App\Http\Resources\DepreciationEntryResource;
use App\Services\FinanceService;

class DepreciationController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    public function store(StoreDepreciation $request, string $assetId): DepreciationEntryResource
    {
        $claims = AuthenticateUser::claims($request);
        $entry = $this->finance->recordDepreciation(
            $assetId,
            $request->validated(),
            $claims['institution_id'] ?? null,
            $claims['sub'],
            $request->header('X-Correlation-ID'),
        );

        return new DepreciationEntryResource($entry);
    }
}
