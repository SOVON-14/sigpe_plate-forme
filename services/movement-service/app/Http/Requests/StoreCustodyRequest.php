<?php

namespace App\Http\Requests;

use App\Enums\CustodyRole;
use App\Enums\HolderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'uuid'],
            'institution_id' => ['nullable', 'uuid'],
            'holder_type' => ['required', 'string', Rule::enum(HolderType::class)],
            'holder_ref' => ['required', 'uuid'],
            'custody_role' => ['required', 'string', Rule::enum(CustodyRole::class)],
            'valid_from' => ['nullable', 'date'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
