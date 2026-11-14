<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockMovement extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'spare_part_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'type' => ['required', 'string', 'in:entry,exit,transfer_out'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'target_warehouse_id' => ['nullable', 'uuid', 'required_if:type,transfer_out'],
            'reason' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:255'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
