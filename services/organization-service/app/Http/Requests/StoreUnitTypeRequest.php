<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
