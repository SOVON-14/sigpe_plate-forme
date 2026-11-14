<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceptionLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'quantity_received' => (float) $this->quantity_received,
            'quantity_accepted' => (float) $this->quantity_accepted,
            'quantity_refused' => (float) $this->quantity_refused,
            'conform' => $this->conform,
            'serial_numbers' => $this->serial_numbers,
            'physical_state_note' => $this->physical_state_note,
            'reserves' => $this->reserves,
            'non_conformity_reason' => $this->non_conformity_reason,
            'asset_id' => $this->asset_id,
            'asset_ids' => $this->asset_ids,
        ];
    }
}
