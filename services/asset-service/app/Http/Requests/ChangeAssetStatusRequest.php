<?php

namespace App\Http\Requests;

use App\Enums\AdministrativeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeAssetStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(AdministrativeStatus::class)],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
