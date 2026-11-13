<?php

namespace App\Services;

use App\Enums\AnomalyType;
use App\Enums\CampaignStatus;
use App\Enums\FindingResult;
use App\Enums\VerificationStatus;
use App\Exceptions\DomainException;
use App\Models\InventoryCampaign;
use App\Models\InventoryFinding;
use Illuminate\Support\Facades\DB;

class FindingService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?:string,id?:string}|null  $actor
     */
    public function record(InventoryCampaign $campaign, array $data, string $recordedBy, ?array $actor = null, ?string $correlationId = null): InventoryFinding
    {
        if ($campaign->status !== CampaignStatus::InProgress) {
            throw new DomainException('FINDING_CAMPAIGN_NOT_OPEN', 'Les constats ne sont enregistrables que sur une campagne en cours.');
        }

        $assetId = $data['asset_id'] ?? null;
        $freeReference = $data['free_reference'] ?? null;

        if ($assetId === null && ($freeReference === null || trim($freeReference) === '')) {
            throw new DomainException('FINDING_REFERENCE_REQUIRED', 'Sans asset_id, une reference libre est obligatoire.');
        }

        $result = FindingResult::from($data['result']);
        $anomalyType = isset($data['anomaly_type']) ? AnomalyType::from($data['anomaly_type']) : null;

        return DB::transaction(function () use ($campaign, $data, $assetId, $freeReference, $result, $anomalyType, $recordedBy, $actor, $correlationId) {
            $finding = InventoryFinding::create([
                'campaign_id' => $campaign->id,
                'institution_id' => $campaign->institution_id,
                'asset_id' => $assetId,
                'free_reference' => $freeReference,
                'designation_found' => $data['designation_found'] ?? null,
                'result' => $result,
                'anomaly_type' => $result === FindingResult::NotFound ? null : $anomalyType,
                'identification_method' => $data['identification_method'],
                'location_found' => $data['location_found'] ?? null,
                'custodian_observed' => $data['custodian_observed'] ?? null,
                'notes' => $data['notes'] ?? null,
                'verification_status' => VerificationStatus::Pending,
                'recorded_by' => $recordedBy,
                'recorded_at' => now(),
            ]);

            $this->outbox->record(
                'inventory.finding_recorded',
                'inventory_finding',
                $finding->id,
                [
                    'finding_id' => $finding->id,
                    'campaign_id' => $campaign->id,
                    'institution_id' => $campaign->institution_id,
                    'asset_id' => $finding->asset_id,
                    'result' => $result->value,
                    'anomaly_type' => $finding->anomaly_type?->value,
                    'identification_method' => $finding->identification_method->value,
                ],
                correlationId: $correlationId,
                institutionId: $campaign->institution_id,
                actor: $actor ?? ['type' => 'user', 'id' => $recordedBy],
            );

            return $finding;
        });
    }

    /**
     * Rapprochement controle : le responsable instruit le constat.
     *
     * @param  array{type?:string,id?:string}|null  $actor
     */
    public function verify(InventoryFinding $finding, string $decision, ?string $notes, string $verifiedBy, ?array $actor = null, ?string $correlationId = null): InventoryFinding
    {
        $map = [
            'confirmed' => VerificationStatus::Confirmed,
            'rejected' => VerificationStatus::Rejected,
            'resolved' => VerificationStatus::Resolved,
        ];

        if (! isset($map[$decision])) {
            throw new DomainException('FINDING_INVALID_DECISION', 'Decision de verification invalide.');
        }

        if (! $finding->isPendingVerification()) {
            throw new DomainException('FINDING_ALREADY_VERIFIED', 'Ce constat a deja ete instruit.');
        }

        $target = $map[$decision];

        return DB::transaction(function () use ($finding, $target, $decision, $notes, $verifiedBy, $actor, $correlationId) {
            $finding = InventoryFinding::lockForUpdate()->findOrFail($finding->id);
            $previous = $finding->verification_status;

            if (! $finding->isPendingVerification()) {
                throw new DomainException('FINDING_ALREADY_VERIFIED', 'Ce constat a deja ete instruit.');
            }

            $finding->verification_status = $target;
            $finding->verified_by = $verifiedBy;
            $finding->verified_at = now();
            $finding->verification_notes = $notes;
            $finding->save();

            $this->outbox->record(
                'inventory.finding_verified',
                'inventory_finding',
                $finding->id,
                [
                    'finding_id' => $finding->id,
                    'campaign_id' => $finding->campaign_id,
                    'institution_id' => $finding->institution_id,
                    'asset_id' => $finding->asset_id,
                    'decision' => $decision,
                    'previous_status' => $previous->value,
                    'verification_status' => $target->value,
                    'notes' => $notes,
                ],
                correlationId: $correlationId,
                institutionId: $finding->institution_id,
                actor: $actor ?? ['type' => 'user', 'id' => $verifiedBy],
            );

            return $finding;
        });
    }
}
