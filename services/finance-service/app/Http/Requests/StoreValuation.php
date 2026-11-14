<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreValuation extends FormRequest
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
            'valuation_type' => ['required', 'in:acquisition,current,net_book,replacement'],
            'valuation_date' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'depreciation_method' => ['nullable', 'in:linear,declining_balance,none'],
            'depreciation_rate_annual' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'useful_life_months' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
