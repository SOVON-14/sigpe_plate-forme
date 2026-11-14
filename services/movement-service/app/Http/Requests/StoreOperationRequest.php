<?php

namespace App\Http\Requests;

use App\Enums\CustodyRole;
use App\Enums\HolderType;
use App\Enums\OperationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(OperationType::class)],
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['uuid'],
            'patrimonial_codes' => ['nullable', 'array'],
            'destination_institution_id' => ['nullable', 'uuid'],
            'institution_id' => ['nullable', 'uuid'],
            'origin_unit_id' => ['nullable', 'uuid'],
            'destination_unit_id' => ['nullable', 'uuid'],
            'operation_ref' => ['nullable', 'uuid'],
            'motif' => ['nullable', 'string', 'max:1000'],
            'require_workflow' => ['nullable', 'boolean'],
            'workflow_id' => ['nullable', 'uuid'],
            'loan_start_at' => ['nullable', 'date'],
            'loan_end_at' => ['nullable', 'date'],
            'custodian_account_id' => ['nullable', 'uuid'],
            'convention_ref' => ['nullable', 'string', 'max:255'],
            'conditions' => ['nullable', 'string', 'max:4000'],
            'custody_role' => ['nullable', 'string', Rule::enum(CustodyRole::class)],
            'holder_type' => ['nullable', 'string', Rule::enum(HolderType::class)],
            'holder_ref' => ['nullable', 'uuid'],
        ];
    }
}
