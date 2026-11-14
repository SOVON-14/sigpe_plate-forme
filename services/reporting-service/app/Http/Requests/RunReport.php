<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunReport extends FormRequest
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
            'institution_id' => ['nullable', 'uuid'],
            'parameters' => ['nullable', 'array'],
        ];
    }
}
