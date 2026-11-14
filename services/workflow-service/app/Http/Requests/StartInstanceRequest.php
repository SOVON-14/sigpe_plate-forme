<?php

namespace App\Http\Requests;

use App\Enums\OperationDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workflow_id' => ['required', 'uuid', 'exists:workflows,id'],
            'institution_id' => ['required', 'uuid'],
            'operation_type' => ['required', 'string', Rule::enum(OperationDomain::class)],
            'operation_ref' => ['required', 'uuid'],
            'context' => ['nullable', 'array'],
        ];
    }
}
