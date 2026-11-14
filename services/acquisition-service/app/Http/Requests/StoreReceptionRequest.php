<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'received_by_name' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.quantity_received' => ['required', 'numeric', 'gt:0'],
            'lines.*.quantity_accepted' => ['nullable', 'numeric', 'min:0'],
            'lines.*.quantity_refused' => ['nullable', 'numeric', 'min:0'],
            'lines.*.conform' => ['nullable', 'boolean'],
            'lines.*.serial_numbers' => ['nullable', 'array', 'max:500'],
            'lines.*.serial_numbers.*' => ['string', 'max:255'],
            'lines.*.physical_state_note' => ['nullable', 'string', 'max:1000'],
            'lines.*.reserves' => ['nullable', 'string', 'max:2000'],
            'lines.*.non_conformity_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
