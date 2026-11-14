<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDisposal extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'uuid'],
            'disposal_type' => ['required', 'in:reform,sale,destruction,loss,theft,donation,other'],
            'reason' => ['required', 'string', 'min:10', 'max:4000'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'incident_reported_at' => ['nullable', 'date'],
        ];
    }
}
