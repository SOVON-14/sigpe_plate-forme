<?php

namespace App\Http\Requests;

use App\Enums\EntryMode;
use App\Enums\PhysicalState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organizational_unit_id' => ['nullable', 'uuid'],
            'category_id' => ['required', 'uuid', 'exists:asset_categories,id'],
            'subcategory_id' => ['nullable', 'uuid', 'exists:asset_subcategories,id'],
            'designation' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'entry_date' => ['required', 'date'],
            'entry_mode' => ['required', 'string', Rule::enum(EntryMode::class)],
            'entry_reference' => ['nullable', 'string', 'max:255'],
            'physical_state' => ['required', 'string', Rule::enum(PhysicalState::class)],
            'location' => ['nullable', 'string', 'max:500'],
            'technical_attributes' => ['nullable', 'array'],
            'value_unknown' => ['nullable', 'boolean'],
            'value_unknown_justification' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('value_unknown')
                && trim((string) $this->input('value_unknown_justification')) === '') {
                $validator->errors()->add(
                    'value_unknown_justification',
                    'Une valeur inconnue exige une justification.',
                );
            }
        });
    }
}
