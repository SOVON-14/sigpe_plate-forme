<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubcategoryRequest;
use App\Http\Resources\AssetCategoryResource;
use App\Http\Resources\AssetSubcategoryResource;
use App\Models\AssetCategory;
use App\Models\AssetSubcategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => AssetCategoryResource::collection(
                AssetCategory::orderBy('code')->get()
            )->resolve(),
        ]);
    }

    public function subcategories(Request $request, AssetCategory $category): JsonResponse
    {
        $institutionId = $request->query('institution_id');

        $query = AssetSubcategory::where('category_id', $category->id);
        if ($institutionId !== null) {
            $query->where(fn (Builder $q) => $q
                ->whereNull('institution_id')
                ->orWhere('institution_id', $institutionId));
        }

        return response()->json([
            'data' => AssetSubcategoryResource::collection(
                $query->orderBy('code')->get()
            )->resolve(),
        ]);
    }

    public function storeSubcategory(StoreSubcategoryRequest $request, AssetCategory $category): JsonResponse
    {
        $subcategory = AssetSubcategory::create([
            'category_id' => $category->id,
            'institution_id' => $request->validated('institution_id'),
            'code' => $request->validated('code'),
            'label' => $request->validated('label'),
        ]);

        return (new AssetSubcategoryResource($subcategory))
            ->response()
            ->setStatusCode(201);
    }
}
