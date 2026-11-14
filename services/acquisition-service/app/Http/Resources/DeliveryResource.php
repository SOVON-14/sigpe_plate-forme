<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'acquisition_request_id' => $this->acquisition_request_id,
            'delivery_note_ref' => $this->delivery_note_ref,
            'carrier' => $this->carrier,
            'expected_at' => $this->expected_at?->toIso8601String(),
            'status' => $this->status->value,
            'receptions_count' => $this->whenCounted('receptions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'receptions' => ReceptionResource::collection($this->whenLoaded('receptions')),
        ];
    }
}
