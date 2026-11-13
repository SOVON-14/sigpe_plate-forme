<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'beneficiary_functional_account_id' => ['required', 'uuid', 'exists:functional_accounts,id'],
            'role_id' => ['nullable', 'uuid', 'exists:roles,id'],
            'institution_id' => ['nullable', 'uuid'],
            'organizational_unit_id' => ['nullable', 'uuid'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after:valid_from'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
