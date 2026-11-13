<?php

namespace App\Http\Requests;

use App\Enums\AnomalyType;
use App\Enums\FindingResult;
use App\Enums\IdentificationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['nullable', 'uuid'],
            'free_reference' => ['nullable', 'string', 'max:255'],
            'designation_found' => ['nullable', 'string', 'max:255'],
            'result' => ['required', 'string', Rule::enum(FindingResult::class)],
            'anomaly_type' => ['nullable', 'string', Rule::enum(AnomalyType::class)],
            'identification_method' => ['required', 'string', Rule::enum(IdentificationMethod::class)],
            'location_found' => ['nullable', 'string', 'max:500'],
            'custodian_observed' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('asset_id') === null
                && trim((string) $this->input('free_reference')) === '') {
                $validator->errors()->add(
                    'free_reference',
                    'Sans asset_id, une reference libre est obligatoire.',
                );
            }
        });
    }
}
