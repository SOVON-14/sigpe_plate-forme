<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\StoreValuation;
use App\Http\Resources\AssetValuationResource;
use App\Http\Resources\DepreciationEntryResource;
use App\Models\AssetValuation;
use App\Models\DepreciationEntry;
use App\Services\FinanceService;
use Illuminate\Http\Request;

class ValuationController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    public function index(Request $request)
    {
        $query = AssetValuation::query()->orderByDesc('valuation_date')->orderByDesc('created_at');

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
        if ($request->query('valuation_type')) {
            $query->where('valuation_type', $request->query('valuation_type'));
        }

        return AssetValuationResource::collection(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function store(StoreValuation $request): AssetValuationResource
    {
        $claims = AuthenticateUser::claims($request);
        $valuation = $this->finance->createValuation(
            $request->validated(),
            $claims['institution_id'] ?? null,
            $claims['sub'],
            $request->header('X-Correlation-ID'),
        );

        return new AssetValuationResource($valuation);
    }

    public function show(AssetValuation $valuation): AssetValuationResource
    {
        return new AssetValuationResource($valuation);
    }

    public function assetView(Request $request, string $assetId)
    {
        $claims = AuthenticateUser::claims($request);

        return response()->json([
            'data' => $this->finance->valuationView($assetId, $claims['institution_id'] ?? null),
        ]);
    }

    public function depreciationIndex(Request $request, string $assetId)
    {
        $institutionId = AuthenticateUser::claims($request)['institution_id'] ?? null;

        return DepreciationEntryResource::collection(
            DepreciationEntry::where('asset_id', $assetId)
                ->when($institutionId !== null, fn ($q) => $q->where('institution_id', $institutionId))
                ->orderByDesc('period_start')
                ->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }
}
