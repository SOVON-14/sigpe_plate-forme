<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryCampaign extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'institution_id', 'designation', 'type', 'status',
        'period_start', 'period_end', 'responsible_account_id',
        'organizational_unit_ids', 'location', 'expected_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => CampaignType::class,
            'status' => CampaignStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'organizational_unit_ids' => 'array',
        ];
    }

    public function agents(): HasMany
    {
        return $this->hasMany(InventoryCampaignAgent::class, 'campaign_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(InventoryFinding::class, 'campaign_id');
    }

    public function scopeForInstitution(Builder $query, string $institutionId): Builder
    {
        return $query->where('institution_id', $institutionId);
    }

    public function scopeWithCounters(Builder $query): Builder
    {
        return $query->withCount([
            'findings as recorded_count',
            'findings as found_count' => fn (Builder $q) => $q->where('result', 'trouve'),
            'findings as not_found_count' => fn (Builder $q) => $q->where('result', 'non_trouve'),
            'findings as anomalies_count' => fn (Builder $q) => $q->whereNotNull('anomaly_type'),
            'findings as pending_count' => fn (Builder $q) => $q->where('verification_status', 'pending'),
        ]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [CampaignStatus::InProgress, CampaignStatus::UnderReview], true);
    }

    public function pendingFindingsCount(): int
    {
        return $this->findings()
            ->where('verification_status', 'pending')
            ->count();
    }
}
