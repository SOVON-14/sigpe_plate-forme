<?php

namespace App\Http\Requests;

use App\Enums\IncidentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IncidentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'incident_type' => ['required', 'string', Rule::enum(IncidentType::class)],
            'description' => ['required', 'string', 'min:10', 'max:4000'],
            'evidence_refs' => ['nullable', 'array'],
            'evidence_refs.*' => ['uuid'],
        ];
    }
}
