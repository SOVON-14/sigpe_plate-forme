<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenameUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
