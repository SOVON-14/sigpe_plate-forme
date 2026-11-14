<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'institution_id' => $this->institution_id,
            'intervention_id' => $this->intervention_id,
            'quality' => $this->quality,
            'timeliness' => $this->timeliness,
            'budget' => $this->budget,
            'parts_quality' => $this->parts_quality,
            'contract_compliance' => $this->contract_compliance,
            'satisfaction' => $this->satisfaction,
            'average_score' => $this->averageScore(),
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
