<?php

namespace App\Http\Resources;

use App\Models\ReportTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportTemplate */
class ReportTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'confidentiality' => $this->confidentiality,
            'parameters' => $this->parameters ?? [],
        ];
    }
}
