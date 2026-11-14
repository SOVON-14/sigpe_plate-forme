<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AdministrativeStatus;
use App\Enums\EntryMode;
use App\Enums\PhysicalState;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\ChangeAssetStatusRequest;
use App\Http\Requests\ChangePhysicalStateRequest;
use App\Http\Requests\ConfirmRattachementRequest;
use App\Http\Requests\RegisterAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Http\Resources\AssetHistoryResource;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetHistory;
use App\Models\AssetSubcategory;
use App\Services\AssetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly AssetService $assets) {}

    public function index(Request $request, string $institutionId): JsonResponse
    {
        $query = Asset::where('institution_id', $institutionId)->orderBy('patrimonial_code');

        if ($status = $request->query('status')) {
            $query->where('administrative_status', $status);
        }
        if ($physical = $request->query('physical_state')) {
            $query->where('physical_state', $physical);
        }
        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }
        if ($unitId = $request->query('organizational_unit_id')) {
            $query->where('organizational_unit_id', $unitId);
        }
        if ($q = $request->query('q')) {
            $query->where(fn (Builder $b) => $b
                ->where('designation', 'like', "%{$q}%")
                ->orWhere('patrimonial_code', 'like', "%{$q}%"));
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            AssetResource::class,
        );
    }

    public function store(RegisterAssetRequest $request, string $institutionId): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $category = AssetCategory::findOrFail($request->validated('category_id'));
        $subcategory = $request->validated('subcategory_id') !== null
            ? AssetSubcategory::find($request->validated('subcategory_id'))
            : null;

        $asset = $this->assets->register(
            institutionId: $institutionId,
            organizationalUnitId: $request->validated('organizational_unit_id'),
            category: $category,
            subcategory: $subcategory,
            designation: $request->validated('designation'),
            description: $request->validated('description'),
            entryDate: $request->validated('entry_date'),
            entryMode: EntryMode::from($request->validated('entry_mode')),
            entryReference: $request->validated('entry_reference'),
            physicalState: PhysicalState::from($request->validated('physical_state')),
            location: $request->validated('location'),
            technicalAttributes: $request->validated('technical_attributes'),
            valueUnknown: $request->boolean('value_unknown'),
            valueUnknownJustification: $request->validated('value_unknown_justification'),
            registeredBy: $claims['sub'],
            correlationId: $request->header('X-Correlation-ID'),
        );

        return (new AssetResource($asset))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/assets/{$asset->id}")
            ->header('ETag', '"' . $asset->updated_at->timestamp . '"');
    }

    public function show(Asset $asset): AssetResource
    {
        return new AssetResource($asset);
    }

    public function update(UpdateAssetRequest $request, Asset $asset): AssetResource
    {
        $claims = AuthenticateUser::claims($request);

        $changes = $request->safe()->except(['motif']);

        return new AssetResource($this->assets->updateDetails(
            $asset,
            $changes,
            $claims['sub'],
            $request->validated('motif'),
            $request->header('X-Correlation-ID'),
        ));
    }

    public function changeStatus(ChangeAssetStatusRequest $request, Asset $asset): AssetResource
    {
        $claims = AuthenticateUser::claims($request);

        return new AssetResource($this->assets->changeAdministrativeStatus(
            $asset,
            AdministrativeStatus::from($request->validated('status')),
            $claims['sub'],
            $request->validated('motif'),
            $request->header('X-Correlation-ID'),
        ));
    }

    public function changePhysicalState(ChangePhysicalStateRequest $request, Asset $asset): AssetResource
    {
        $claims = AuthenticateUser::claims($request);

        return new AssetResource($this->assets->changePhysicalState(
            $asset,
            PhysicalState::from($request->validated('physical_state')),
            $claims['sub'],
            $request->validated('motif'),
            $request->header('X-Correlation-ID'),
        ));
    }

    public function confirmRattachement(ConfirmRattachementRequest $request, Asset $asset): AssetResource
    {
        $claims = AuthenticateUser::claims($request);

        return new AssetResource($this->assets->confirmRattachement(
            $asset,
            $request->validated('institution_id'),
            $request->validated('organizational_unit_id'),
            $claims['sub'],
            $request->header('X-Correlation-ID'),
        ));
    }

    public function histories(Request $request, Asset $asset): JsonResponse
    {
        return $this->paginated(
            AssetHistory::where('asset_id', $asset->id)
                ->orderByDesc('changed_at')
                ->paginate(min((int) $request->query('per_page', 20), 100)),
            AssetHistoryResource::class,
        );
    }
}
