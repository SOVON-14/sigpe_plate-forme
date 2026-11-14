<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keycloak_sub' => ['required', 'string', 'min:1', 'max:255'],
            'display_name' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
