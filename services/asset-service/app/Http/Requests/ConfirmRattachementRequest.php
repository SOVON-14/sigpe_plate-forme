<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmRattachementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'uuid'],
            'organizational_unit_id' => ['nullable', 'uuid'],
        ];
    }
}
