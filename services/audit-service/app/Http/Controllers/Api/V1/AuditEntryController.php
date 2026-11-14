<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Resources\AuditEntryDetailResource;
use App\Http\Resources\AuditEntryResource;
use App\Models\AuditEntry;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AuditEntryController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $query = $this->scopedQuery($request)
            ->orderByDesc('seq');

        $this->applyFilters($query, $request);

        $perPage = min((int) $request->query('per_page', 25), 100);

        return AuditEntryResource::collection($query->paginate($perPage));
    }

    public function show(AuditEntry $auditEntry): AuditEntryDetailResource
    {
        return new AuditEntryDetailResource($auditEntry);
    }

    public function stats(Request $request)
    {
        $institutionId = AuthenticateUser::claims($request)['institution_id'] ?? null;

        return response()->json([
            'data' => $this->audit->stats(
                $request->query('occurred_from'),
                $request->query('occurred_to'),
                $institutionId,
            ),
        ]);
    }

    /**
     * @return Builder<AuditEntry>
     */
    private function scopedQuery(Request $request): Builder
    {
        $query = AuditEntry::query();
        $institutionId = AuthenticateUser::claims($request)['institution_id'] ?? null;

        if ($institutionId !== null) {
            $query->where('institution_id', $institutionId);
        }

        return $query;
    }

    /**
     * @param  Builder<AuditEntry>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $map = [
            'producer' => 'producer',
            'event_type' => 'event_type',
            'institution_id' => 'institution_id',
            'actor_id' => 'actor_id',
            'resource_type' => 'resource_type',
            'resource_id' => 'resource_id',
            'operation' => 'operation',
            'result' => 'result',
        ];

        foreach ($map as $param => $column) {
            if ($request->query($param) !== null) {
                $query->where($column, $request->query($param));
            }
        }

        if ($request->query('occurred_from') !== null) {
            $query->where('occurred_at', '>=', $request->query('occurred_from'));
        }
        if ($request->query('occurred_to') !== null) {
            $query->where('occurred_at', '<=', $request->query('occurred_to'));
        }
        if ($request->query('q') !== null) {
            $q = '%'.$request->query('q').'%';
            $query->where(function (Builder $w) use ($q): void {
                $w->where('operation', 'like', $q)
                    ->orWhere('reason', 'like', $q);
            });
        }
    }
}
