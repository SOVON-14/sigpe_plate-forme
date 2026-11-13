<?php

namespace App\Models;

use App\Enums\AnomalyType;
use App\Enums\FindingResult;
use App\Enums\IdentificationMethod;
use App\Enums\VerificationStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryFinding extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'campaign_id', 'institution_id', 'asset_id', 'free_reference',
        'designation_found', 'result', 'anomaly_type', 'identification_method',
        'location_found', 'custodian_observed', 'notes',
        'verification_status', 'verified_by', 'verified_at', 'verification_notes',
        'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => FindingResult::class,
            'anomaly_type' => AnomalyType::class,
            'identification_method' => IdentificationMethod::class,
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(InventoryCampaign::class, 'campaign_id');
    }

    public function scopeForCampaign(Builder $query, string $campaignId): Builder
    {
        return $query->where('campaign_id', $campaignId);
    }

    public function isPendingVerification(): bool
    {
        return $this->verification_status === VerificationStatus::Pending;
    }
}
