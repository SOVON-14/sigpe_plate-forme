<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetUnitHeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position_id' => ['required', 'uuid', 'exists:positions,id'],
        ];
    }
}
