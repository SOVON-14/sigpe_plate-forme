<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'uuid'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
