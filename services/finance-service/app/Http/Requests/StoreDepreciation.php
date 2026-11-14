<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepreciation extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'in:linear,declining_balance'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
