<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignResponsibleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'functional_account_id' => ['required', 'uuid'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
