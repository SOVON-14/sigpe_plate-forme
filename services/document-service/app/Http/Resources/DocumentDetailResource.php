<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;

/** @mixin Document */
class DocumentDetailResource extends DocumentResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'description' => $this->description,
            'issuer' => $this->issuer,
            'document_date' => $this->document_date?->toDateString(),
            'versions' => DocumentVersionResource::collection($this->whenLoaded('versions')),
        ]);
    }
}
