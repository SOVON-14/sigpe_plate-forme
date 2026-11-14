<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSparePart extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'min:2', 'max:100'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['required', 'string', 'min:1', 'max:50'],
            'category_id' => ['nullable', 'uuid'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reorder_threshold' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
