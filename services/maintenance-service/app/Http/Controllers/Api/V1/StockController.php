<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\StoreSparePart;
use App\Http\Requests\StoreStockMovement;
use App\Http\Requests\StoreWarehouse;
use App\Http\Resources\SparePartResource;
use App\Http\Resources\StockMovementResource;
use App\Http\Resources\WarehouseResource;
use App\Models\SparePart;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly MaintenanceService $maintenance) {}

    // ------------------------------------------------------------------
    // Magasins
    // ------------------------------------------------------------------

    public function warehousesIndex(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        return response()->json([
            'data' => WarehouseResource::collection(
                Warehouse::forInstitution($claims['institution_id'])->orderBy('name')->get()
            ),
        ]);
    }

    public function warehouseStore(StoreWarehouse $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $warehouse = $this->maintenance->createWarehouse(
            $request->validated(),
            (string) $claims['institution_id'],
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new WarehouseResource($warehouse))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/warehouses/{$warehouse->id}");
    }

    // ------------------------------------------------------------------
    // Pieces de rechange
    // ------------------------------------------------------------------

    public function partsIndex(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = SparePart::forInstitution($claims['institution_id'])
            ->with('stockLevels')
            ->orderBy('reference');

        if ($q = $request->query('q')) {
            $query->where(fn ($qb) => $qb->where('reference', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
        }
        if ($warehouseId = $request->query('warehouse_id')) {
            $query->whereHas('stockLevels', fn ($q) => $q->where('warehouse_id', $warehouseId));
        }
        if ($request->boolean('below_threshold')) {
            $query->whereNotNull('reorder_threshold');
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            SparePartResource::class,
        );
    }

    public function partStore(StoreSparePart $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $part = $this->maintenance->createSparePart(
            $request->validated(),
            (string) $claims['institution_id'],
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new SparePartResource($part->load('stockLevels')))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/spare-parts/{$part->id}");
    }

    public function partShow(SparePart $part): SparePartResource
    {
        return new SparePartResource($part->load('stockLevels'));
    }

    // ------------------------------------------------------------------
    // Mouvements de stock
    // ------------------------------------------------------------------

    public function movementsIndex(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = StockMovement::forInstitution($claims['institution_id'])
            ->orderByDesc('occurred_at');

        if ($partId = $request->query('spare_part_id')) {
            $query->where('spare_part_id', $partId);
        }
        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            StockMovementResource::class,
        );
    }

    public function movementStore(StoreStockMovement $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $movement = $this->maintenance->recordStockMovement(
            $request->validated(),
            (string) $claims['institution_id'],
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new StockMovementResource($movement))
            ->response()
            ->setStatusCode(201);
    }
}
