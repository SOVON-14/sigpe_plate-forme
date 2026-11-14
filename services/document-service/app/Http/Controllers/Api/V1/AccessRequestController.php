<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\DecideAccessRequest;
use App\Http\Resources\AccessRequestResource;
use App\Models\DocumentAccessRequest;
use App\Services\DocumentService;

class AccessRequestController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function decision(DecideAccessRequest $request, DocumentAccessRequest $accessRequest): AccessRequestResource
    {
        $claims = AuthenticateUser::claims($request);
        $decided = $this->documents->decideAccessRequest(
            $accessRequest,
            $request->input('decision'),
            $request->input('motif'),
            $claims['sub'] ?? 'unknown',
            $request->header('X-Correlation-ID'),
        );

        return new AccessRequestResource($decided);
    }
}
