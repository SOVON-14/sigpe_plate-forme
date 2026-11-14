<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use PaginatesResponses;

    public function index(Request $request): JsonResponse
    {
        $query = Role::with('permissions')->orderBy('code');

        if ($request->boolean('system_only')) {
            $query->where('is_system', true);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            RoleResource::class,
        );
    }

    public function show(Role $role): RoleResource
    {
        $role->load('permissions');

        return new RoleResource($role);
    }
}
