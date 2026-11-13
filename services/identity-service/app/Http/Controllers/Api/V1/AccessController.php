<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateFunctionalAccount;
use App\Services\EffectiveAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessController extends Controller
{
    public function __construct(private readonly EffectiveAccessService $access) {}

    public function permissions(Request $request): JsonResponse
    {
        /** @var \App\Models\FunctionalAccount $account */
        $account = $request->attributes->get(AuthenticateFunctionalAccount::ACCOUNT_ATTRIBUTE);

        $permissions = $this->access->permissionsFor(
            $account,
            $request->query('institution_id'),
            $request->query('organizational_unit_id'),
        );

        return response()->json([
            'account_id' => $account->id,
            'permissions' => $permissions->values(),
        ]);
    }
}
