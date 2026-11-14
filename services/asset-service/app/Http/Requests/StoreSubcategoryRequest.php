<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubcategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
