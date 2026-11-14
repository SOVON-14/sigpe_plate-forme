<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransitionDocument extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target_stage' => ['required', 'in:archives_intermediaires,archives_definitives,eliminated'],
            'motif' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
