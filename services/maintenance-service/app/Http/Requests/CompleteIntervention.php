<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteIntervention extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'costs' => ['required', 'array'],
            'costs.labor' => ['required', 'numeric', 'min:0'],
            'costs.parts' => ['required', 'numeric', 'min:0'],
            'costs.supplies' => ['required', 'numeric', 'min:0'],
            'costs.transport' => ['required', 'numeric', 'min:0'],
            'costs.external_services' => ['required', 'numeric', 'min:0'],
            'costs.other' => ['required', 'numeric', 'min:0'],
            'outcome_note' => ['required', 'string', 'min:3', 'max:4000'],
            'parts_used' => ['nullable', 'array'],
            'parts_used.*.spare_part_id' => ['required', 'uuid'],
            'parts_used.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
