<?php

namespace App\Http\Requests;

use App\Enums\MaintenanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterventionPlan extends FormRequest
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
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'trigger' => ['required', 'array'],
            'trigger.kind' => ['required', 'string', 'in:date,mileage,hours,cycles,condition'],
            'trigger.interval_value' => ['nullable', 'numeric', 'gt:0'],
            'trigger.interval_unit' => ['nullable', 'string', 'max:50'],
            'trigger.next_due_at' => ['nullable', 'date'],
            'assigned_supplier_id' => ['nullable', 'uuid'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
