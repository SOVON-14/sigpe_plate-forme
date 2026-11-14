<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'category_id' => $this->category_id,
            'category_code' => $this->whenLoaded('category', fn () => $this->category?->code),
            'institution_id' => $this->institution_id,
            'unit_id' => $this->unit_id,
            'asset_id' => $this->asset_id,
            'confidentiality' => $this->confidentiality->value,
            'lifecycle_stage' => $this->lifecycle_stage->value,
            'current_version' => $this->current_version,
            'version_count' => $this->whenCounted('versions'),
            'retention_until' => $this->retention_until?->toDateString(),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
