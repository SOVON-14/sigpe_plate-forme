<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationHistoryResource;
use App\Models\Institution;
use App\Models\OrganizationHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    use PaginatesResponses;

    public function index(Request $request, Institution $institution): JsonResponse
    {
        $query = OrganizationHistory::where('institution_id', $institution->id)
            ->orderByDesc('changed_at');

        if ($entityType = $request->query('entity_type')) {
            $query->where('entity_type', $entityType);
        }
        if ($entityId = $request->query('entity_id')) {
            $query->where('entity_id', $entityId);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            OrganizationHistoryResource::class,
        );
    }
}
