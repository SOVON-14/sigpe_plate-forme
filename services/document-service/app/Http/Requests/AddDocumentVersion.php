<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddDocumentVersion extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:25600'],
            'erratum_motif' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
