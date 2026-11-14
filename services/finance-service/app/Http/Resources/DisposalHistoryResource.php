<?php

namespace App\Http\Resources;

use App\Models\DisposalHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DisposalHistory */
class DisposalHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'disposal_id' => $this->disposal_id,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor_id' => $this->actor_id,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
