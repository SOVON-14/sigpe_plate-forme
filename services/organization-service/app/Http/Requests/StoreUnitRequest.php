<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_type_id' => ['required', 'uuid'],
            'parent_id' => ['nullable', 'uuid'],
            'code' => ['required', 'string', 'min:2', 'max:50'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
