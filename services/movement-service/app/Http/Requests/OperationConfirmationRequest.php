<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OperationConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:2000'],
            'received_state_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
