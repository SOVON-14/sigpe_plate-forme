<?php

namespace App\Http\Requests;

use App\Enums\MaintenanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'uuid'],
            'type' => ['required', 'string', Rule::enum(MaintenanceType::class)],
            'description' => ['required', 'string', 'min:3', 'max:4000'],
            'urgency' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'requested_by_unit_id' => ['nullable', 'uuid'],
            'symptoms' => ['nullable', 'string', 'max:4000'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'is_urgent_track' => ['nullable', 'boolean'],
            'urgent_justification' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
