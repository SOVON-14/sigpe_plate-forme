<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_id' => $this->delivery_id,
            'acquisition_request_id' => $this->acquisition_request_id,
            'received_by' => $this->received_by,
            'received_by_name' => $this->received_by_name,
            'status' => $this->status->value,
            'lines' => ReceptionLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
