<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePreferences extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.event_type' => ['required', 'string', 'max:150'],
            'preferences.*.channel' => ['required', 'in:in_app,email,sms'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }
}
