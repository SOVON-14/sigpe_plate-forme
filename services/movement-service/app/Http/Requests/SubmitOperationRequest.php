<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workflow_instance_id' => ['nullable', 'uuid'],
            'context' => ['nullable', 'array'],
        ];
    }
}
