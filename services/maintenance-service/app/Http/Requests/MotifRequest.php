<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MotifRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motif' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
