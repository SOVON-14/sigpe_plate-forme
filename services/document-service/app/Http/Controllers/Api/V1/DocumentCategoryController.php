<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentCategoryResource;
use App\Services\DocumentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentCategoryController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(): AnonymousResourceCollection
    {
        return DocumentCategoryResource::collection($this->documents->nationalCategories());
    }
}
