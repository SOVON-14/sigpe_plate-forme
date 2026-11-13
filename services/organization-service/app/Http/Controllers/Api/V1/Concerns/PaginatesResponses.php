<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

trait PaginatesResponses
{
    protected function paginated(LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        $collection = $resourceClass::collection($paginator->items());

        return response()->json([
            'data' => $collection->resolve(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
