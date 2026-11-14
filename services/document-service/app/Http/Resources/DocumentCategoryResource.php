<?php

namespace App\Http\Resources;

use App\Models\DocumentCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentCategory */
class DocumentCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'label' => $this->label,
            'scope' => $this->scope,
            'parent_id' => $this->parent_id,
            'is_active' => $this->is_active,
        ];
    }
}
