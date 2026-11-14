<?php

namespace App\Http\Resources;

use App\Models\ReportRun;
use Illuminate\Http\Request;

/** @mixin ReportRun */
class ReportRunDetailResource extends ReportRunResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'result' => $this->when($this->status === 'completed', $this->result),
            'error' => $this->error,
        ]);
    }
}
