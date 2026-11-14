<?php

namespace App\Http\Resources;

use App\Models\AssetCost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetCost */
class AssetCostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'institution_id' => $this->institution_id,
            'category' => $this->category->value,
            'source_type' => $this->source_type->value,
            'source_id' => $this->source_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'incurred_at' => $this->incurred_at->toDateString(),
            'beneficiary_account_id' => $this->beneficiary_account_id,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
