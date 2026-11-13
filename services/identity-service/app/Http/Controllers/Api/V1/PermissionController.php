<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    use PaginatesResponses;

    public function index(Request $request): JsonResponse
    {
        $query = Permission::orderBy('domain')->orderBy('action');

        if ($domain = $request->query('domain')) {
            $query->where('domain', $domain);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            PermissionResource::class,
        );
    }
}
