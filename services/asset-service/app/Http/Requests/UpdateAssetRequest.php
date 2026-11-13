<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'designation' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'organizational_unit_id' => ['sometimes', 'nullable', 'uuid'],
            'technical_attributes' => ['sometimes', 'nullable', 'array'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
