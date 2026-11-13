<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_id' => ['required', 'uuid', 'exists:roles,id'],
            'institution_id' => ['nullable', 'uuid'],
            'organizational_unit_id' => ['nullable', 'uuid'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
