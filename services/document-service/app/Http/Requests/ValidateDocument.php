<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateDocument extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version_id' => ['nullable', 'uuid'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
