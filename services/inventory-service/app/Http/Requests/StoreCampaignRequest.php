<?php

namespace App\Http\Requests;

use App\Enums\CampaignType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'designation' => ['required', 'string', 'min:1', 'max:255'],
            'type' => ['required', 'string', Rule::enum(CampaignType::class)],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'responsible_account_id' => ['required', 'uuid'],
            'organizational_unit_ids' => ['nullable', 'array', 'max:500'],
            'organizational_unit_ids.*' => ['uuid'],
            'location' => ['nullable', 'string', 'max:500'],
            'expected_count' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
