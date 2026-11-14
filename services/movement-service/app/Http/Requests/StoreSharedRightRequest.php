<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSharedRightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'uuid'],
            'responsible_institution_id' => ['required', 'uuid'],
            'beneficiary_institution_id' => ['required', 'uuid', 'different:responsible_institution_id'],
            'usage_scope' => ['required', 'string', 'min:3', 'max:500'],
            'responsibilities' => ['nullable', 'string', 'max:2000'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'convention_ref' => ['nullable', 'string', 'max:255'],
        ];
    }
}
