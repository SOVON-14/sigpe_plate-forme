<?php

namespace App\Http\Resources;

use App\Models\SavedView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SavedView */
class SavedViewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'name' => $this->name,
            'profile' => $this->profile,
            'filters' => $this->filters,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
