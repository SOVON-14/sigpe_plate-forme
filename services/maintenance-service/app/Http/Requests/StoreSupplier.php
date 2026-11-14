<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplier extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'specialty' => ['required', 'string', 'min:2', 'max:255'],
            'scope' => ['nullable', 'string', 'in:national,ministerial'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'contract_ref' => ['nullable', 'string', 'max:255'],
            'contract_end_at' => ['nullable', 'date'],
        ];
    }
}
