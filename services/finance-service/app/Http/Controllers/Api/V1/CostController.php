<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\StoreCost;
use App\Http\Resources\AssetCostResource;
use App\Models\AssetCost;
use App\Services\FinanceService;
use Illuminate\Http\Request;

class CostController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    public function index(Request $request)
    {
        $query = AssetCost::query()->orderByDesc('incurred_at')->orderByDesc('created_at');

        $institutionId = AuthenticateUser::claims($request)['institution_id'] ?? null;
        if ($institutionId !== null) {
            $query->where('institution_id', $institutionId);
        }
        if ($request->query('asset_id')) {
            $query->where('asset_id', $request->query('asset_id'));
        }
        if ($request->query('institution_id')) {
            $query->where('institution_id', $request->query('institution_id'));
        }
        if ($request->query('source_type')) {
            $query->where('source_type', $request->query('source_type'));
        }

        return AssetCostResource::collection(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function store(StoreCost $request): AssetCostResource
    {
        $claims = AuthenticateUser::claims($request);
        $cost = $this->finance->createCost(
            $request->validated(),
            $claims['institution_id'] ?? null,
            $claims['sub'],
            $request->header('X-Correlation-ID'),
        );

        return new AssetCostResource($cost);
    }

    public function show(AssetCost $cost): AssetCostResource
    {
        return new AssetCostResource($cost);
    }

    public function assetSummary(Request $request, string $assetId)
    {
        $institutionId = AuthenticateUser::claims($request)['institution_id'] ?? null;

        return response()->json(['data' => $this->finance->costSummary($assetId, $institutionId)]);
    }
}
