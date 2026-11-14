<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateView extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'profile' => ['sometimes', 'string', 'max:60'],
            'filters' => ['required', 'array'],
        ];
    }
}
