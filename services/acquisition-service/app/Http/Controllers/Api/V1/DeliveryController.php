<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\StoreDeliveryRequest;
use App\Http\Requests\StoreReceptionRequest;
use App\Http\Resources\DeliveryResource;
use App\Http\Resources\ReceptionResource;
use App\Models\AcquisitionDelivery;
use App\Models\AcquisitionRequest;
use App\Services\AcquisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function __construct(private readonly AcquisitionService $acquisitions) {}

    public function store(StoreDeliveryRequest $request, AcquisitionRequest $acquisition): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $delivery = $this->acquisitions->recordDelivery(
            $acquisition,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new DeliveryResource($delivery))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/deliveries/{$delivery->id}");
    }

    public function receptionsIndex(Request $request, AcquisitionDelivery $delivery): JsonResponse
    {
        return response()->json([
            'data' => ReceptionResource::collection(
                $delivery->receptions()->with('lines')->orderByDesc('created_at')
                    ->paginate(min((int) $request->query('per_page', 20), 100))
                    ->items()
            ),
        ]);
    }

    public function recordReception(StoreReceptionRequest $request, AcquisitionDelivery $delivery): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $reception = $this->acquisitions->recordReception(
            $delivery,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new ReceptionResource($reception))
            ->response()
            ->setStatusCode(201);
    }
}
