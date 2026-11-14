<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveInvestigation extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution' => ['required', 'in:asset_found,loss_confirmed,theft_confirmed'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
