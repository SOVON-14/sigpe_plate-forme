<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocument extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isMultipart = $this->hasFile('file');

        return [
            'file' => [$isMultipart ? 'required' : 'nullable', 'file', 'max:25600'],
            'title' => ['required', 'string', 'min:3', 'max:300'],
            'category_id' => ['required', 'uuid'],
            'description' => ['nullable', 'string', 'max:5000'],
            'institution_id' => ['nullable', 'uuid'],
            'unit_id' => ['nullable', 'uuid'],
            'asset_id' => ['nullable', 'uuid'],
            'confidentiality' => ['nullable', 'in:public_administratif,interne,confidentiel,hautement_sensible'],
            'retention_until' => ['nullable', 'date'],
            'document_date' => ['nullable', 'date'],
            'issuer' => ['nullable', 'string', 'max:300'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
