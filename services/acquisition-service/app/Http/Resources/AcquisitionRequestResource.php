<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcquisitionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'institution_id' => $this->institution_id,
            'organizational_unit_id' => $this->organizational_unit_id,
            'requester_account_id' => $this->requester_account_id,
            'title' => $this->title,
            'motif' => $this->motif,
            'priority' => $this->priority->value,
            'needed_by' => $this->needed_by?->toIso8601String(),
            'status' => $this->status->value,
            'workflow_instance_id' => $this->workflow_instance_id,
            'supplier_name' => $this->supplier_name,
            'supplier_ref' => $this->supplier_ref,
            'order_ref' => $this->order_ref,
            'contracted_total_value' => $this->contracted_total_value !== null ? (float) $this->contracted_total_value : null,
            'total_estimated_value' => $this->total_estimated_value !== null ? (float) $this->total_estimated_value : null,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'items' => AcquisitionItemResource::collection($this->whenLoaded('items')),
            'deliveries' => DeliveryResource::collection($this->whenLoaded('deliveries')),
        ];
    }
}
