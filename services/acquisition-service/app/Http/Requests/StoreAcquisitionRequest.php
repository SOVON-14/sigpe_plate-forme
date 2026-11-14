<?php

namespace App\Http\Requests;

use App\Enums\RequestPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcquisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'organizational_unit_id' => ['nullable', 'uuid'],
            'motif' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', 'string', Rule::enum(RequestPriority::class)],
            'needed_by' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'min:3', 'max:500'],
            'items.*.specifications' => ['nullable', 'string', 'max:4000'],
            'items.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.estimated_unit_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.value_justification' => ['nullable', 'string', 'max:1000'],
            'items.*.category_id' => ['nullable', 'uuid'],
        ];
    }
}
