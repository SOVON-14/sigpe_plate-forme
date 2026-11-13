<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'institution_id' => $this->institution_id,
            'asset_id' => $this->asset_id,
            'free_reference' => $this->free_reference,
            'designation_found' => $this->designation_found,
            'result' => $this->result->value,
            'anomaly_type' => $this->anomaly_type?->value,
            'identification_method' => $this->identification_method->value,
            'location_found' => $this->location_found,
            'custodian_observed' => $this->custodian_observed,
            'notes' => $this->notes,
            'verification_status' => $this->verification_status->value,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verification_notes' => $this->verification_notes,
            'recorded_by' => $this->recorded_by,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
        ];
    }
}
