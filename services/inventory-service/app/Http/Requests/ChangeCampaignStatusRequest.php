<?php

namespace App\Http\Requests;

use App\Enums\CampaignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeCampaignStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                CampaignStatus::InProgress->value,
                CampaignStatus::UnderReview->value,
                CampaignStatus::Closed->value,
                CampaignStatus::Cancelled->value,
            ])],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
