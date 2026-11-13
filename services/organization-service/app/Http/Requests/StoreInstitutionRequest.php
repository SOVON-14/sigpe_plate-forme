<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[A-Z0-9-]+$/', 'unique:institutions,code'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'type' => ['nullable', 'string', Rule::in(['administration_centrale', 'ministere', 'autre'])],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
