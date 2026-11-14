<?php

namespace App\Http\Resources;

use App\Models\ReportRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportRun */
class ReportRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'template_code' => $this->template_code,
            'institution_id' => $this->institution_id,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'parameters' => $this->parameters ?? [],
            'status' => $this->status,
            'confidentiality' => $this->confidentiality,
            'generated_by' => $this->generated_by,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'result_summary' => isset($this->result['row_count'])
                ? ['row_count' => $this->result['row_count'], 'title' => $this->result['title'] ?? null]
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
