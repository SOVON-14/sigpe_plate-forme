<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCost extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'uuid'],
            'category' => ['required', 'in:acquisition_cost,maintenance_cost,repair,transport,insurance,shared_cost_share,other'],
            'source_type' => ['sometimes', 'in:acquisition,maintenance,movement,manual,other'],
            'source_id' => ['nullable', 'uuid'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'incurred_at' => ['required', 'date'],
            'beneficiary_account_id' => ['nullable', 'uuid'],
            'description' => ['required', 'string', 'max:2000'],
        ];
    }
}
