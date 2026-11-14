<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWarehouse extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'organizational_unit_id' => ['nullable', 'uuid'],
        ];
    }
}
