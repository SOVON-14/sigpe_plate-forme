<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OfferSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_name' => ['required', 'string', 'min:2', 'max:255'],
            'supplier_ref' => ['nullable', 'uuid'],
            'order_ref' => ['nullable', 'string', 'max:255'],
            'contracted_total_value' => ['nullable', 'numeric', 'min:0'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
