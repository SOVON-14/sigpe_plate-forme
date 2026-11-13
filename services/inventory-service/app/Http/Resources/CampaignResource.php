<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'designation' => $this->designation,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'responsible_account_id' => $this->responsible_account_id,
            'organizational_unit_ids' => $this->organizational_unit_ids,
            'location' => $this->location,
            'counters' => [
                'expected' => $this->expected_count ?? 0,
                'recorded' => $this->recorded_count ?? $this->findings()->count(),
                'found' => $this->found_count ?? $this->findings()->where('result', 'trouve')->count(),
                'not_found' => $this->not_found_count ?? $this->findings()->where('result', 'non_trouve')->count(),
                'anomalies' => $this->anomalies_count ?? $this->findings()->whereNotNull('anomaly_type')->count(),
                'pending_verification' => $this->pending_count ?? $this->findings()->where('verification_status', 'pending')->count(),
            ],
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
