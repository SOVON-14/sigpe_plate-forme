<?php

namespace App\Http\Requests;

use App\Enums\AssigneeType;
use App\Enums\OperationDomain;
use App\Enums\StepMode;
use App\Enums\WorkflowStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['nullable', 'uuid'],
            'parent_workflow_id' => ['nullable', 'uuid', 'exists:workflows,id'],
            'domain' => ['required', 'string', Rule::enum(OperationDomain::class)],
            'code' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', Rule::enum(WorkflowStatus::class)],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.step_group' => ['required', 'integer', 'min:1'],
            'steps.*.sequence' => ['nullable', 'integer', 'min:1'],
            'steps.*.name' => ['required', 'string', 'min:1', 'max:255'],
            'steps.*.assignee_type' => ['required', 'string', Rule::enum(AssigneeType::class)],
            'steps.*.assignee_ref' => ['required', 'string', 'min:1', 'max:255'],
            'steps.*.mode' => ['nullable', 'string', Rule::enum(StepMode::class)],
            'steps.*.sla_hours' => ['nullable', 'integer', 'min:1'],
            'steps.*.is_national_obligatory' => ['nullable', 'boolean'],
        ];
    }
}
