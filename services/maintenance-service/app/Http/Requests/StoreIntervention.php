<?php

namespace App\Http\Requests;

use App\Enums\MaintenanceType;
use App\Enums\ProviderKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntervention extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id' => ['nullable', 'uuid'],
            'asset_id' => ['required', 'uuid'],
            'type' => ['required', 'string', Rule::enum(MaintenanceType::class)],
            'description' => ['required', 'string', 'min:3', 'max:4000'],
            'provider_kind' => ['nullable', 'string', Rule::enum(ProviderKind::class)],
            'supplier_id' => ['nullable', 'uuid', 'required_if:provider_kind,external'],
            'assigned_account_id' => ['nullable', 'uuid'],
            'planned_at' => ['nullable', 'date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
