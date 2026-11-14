<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\EvaluateSupplier;
use App\Http\Requests\StoreSupplier;
use App\Http\Resources\SupplierEvaluationResource;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use PaginatesResponses;

    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function index(Request $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $query = Supplier::visibleTo($claims['institution_id'])
            ->with('evaluations')
            ->orderBy('name');

        if ($q = $request->query('q')) {
            $query->where(fn ($qb) => $qb->where('name', 'like', "%{$q}%")->orWhere('specialty', 'like', "%{$q}%"));
        }
        if ($specialty = $request->query('specialty')) {
            $query->where('specialty', 'like', "%{$specialty}%");
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            SupplierResource::class,
        );
    }

    public function store(StoreSupplier $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $nationalAllowed = in_array('maintenance.suppliers.national', $claims['permissions'], true);

        $supplier = $this->maintenance->createSupplier(
            $request->validated(),
            $claims['institution_id'],
            $claims['sub'],
            $nationalAllowed,
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new SupplierResource($supplier))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/suppliers/{$supplier->id}");
    }

    public function show(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($supplier->load('evaluations'));
    }

    public function evaluate(EvaluateSupplier $request, Supplier $supplier): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        // L'evaluation est ministerielle : un prestataire national
        // peut etre evalue par chaque ministere dans son perimetre.
        $institutionId = $claims['institution_id'] ?? $supplier->institution_id;
        if ($institutionId === null) {
            abort(422, 'institution_id requis pour evaluer un prestataire national.');
        }

        $evaluation = $this->maintenance->evaluateSupplier(
            $supplier,
            $request->validated(),
            (string) $institutionId,
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new SupplierEvaluationResource($evaluation))
            ->response()
            ->setStatusCode(201);
    }
}
