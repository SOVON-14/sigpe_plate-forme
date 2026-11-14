<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approved,denied'],
            'motif' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
