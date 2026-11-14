<?php

namespace App\Http\Resources;

use App\Models\AssetValuation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetValuation */
class AssetValuationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'institution_id' => $this->institution_id,
            'valuation_type' => $this->valuation_type->value,
            'valuation_date' => $this->valuation_date->toDateString(),
            'amount' => $this->amount !== null ? (float) $this->amount : null,
            'currency' => $this->currency,
            'is_determinate' => $this->is_determinate,
            'depreciation_method' => $this->depreciation_method,
            'depreciation_rate_annual' => $this->depreciation_rate_annual !== null ? (float) $this->depreciation_rate_annual : null,
            'useful_life_months' => $this->useful_life_months,
            'notes' => $this->notes,
            'recorded_by' => $this->recorded_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
