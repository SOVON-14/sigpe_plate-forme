<?php

namespace App\Http\Resources;

use App\Models\AuditEntry;
use Illuminate\Http\Request;

/** @mixin AuditEntry */
class AuditEntryDetailResource extends AuditEntryResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'payload' => $this->payload,
        ]);
    }
}
