<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EvaluateSupplier extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $score = ['nullable', 'integer', 'min:1', 'max:5'];

        return [
            'intervention_id' => ['required', 'uuid'],
            'quality' => $score,
            'timeliness' => $score,
            'budget' => $score,
            'parts_quality' => $score,
            'contract_compliance' => $score,
            'satisfaction' => $score,
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
