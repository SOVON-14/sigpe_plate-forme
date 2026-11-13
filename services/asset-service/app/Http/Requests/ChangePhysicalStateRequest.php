<?php

namespace App\Http\Requests;

use App\Enums\PhysicalState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePhysicalStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'physical_state' => ['required', 'string', Rule::enum(PhysicalState::class)],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
