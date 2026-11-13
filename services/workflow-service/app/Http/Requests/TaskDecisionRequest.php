<?php

namespace App\Http\Requests;

use App\Enums\TaskDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::enum(TaskDecision::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
