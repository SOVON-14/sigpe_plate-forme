<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Exceptions\DomainException;
use App\Models\InventoryCampaign;
use App\Models\InventoryCampaignAgent;
use Illuminate\Support\Facades\DB;

class InventoryCampaignService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    /**
     * @param  array{designation:string,type:CampaignType,period_start:string,period_end:string,responsible_account_id:string,organizational_unit_ids?:array<int,string>|null,location?:string|null,expected_count?:int|null}  $data
     * @param  array{type?:string,id?:string}|null  $actor
     */
    public function create(array $data, string $institutionId, ?string $actorId, ?array $actor = null, ?string $correlationId = null): InventoryCampaign
    {
        if ($data['period_end'] < $data['period_start']) {
            throw new DomainException('CAMPAIGN_INVALID_PERIOD', 'La fin de periode doit etre posterieure au debut.');
        }

        return DB::transaction(function () use ($data, $institutionId, $actorId, $actor, $correlationId) {
            $campaign = InventoryCampaign::create([
                'institution_id' => $institutionId,
                'designation' => $data['designation'],
                'type' => $data['type'],
                'status' => CampaignStatus::Draft,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'responsible_account_id' => $data['responsible_account_id'],
                'organizational_unit_ids' => $data['organizational_unit_ids'] ?? null,
                'location' => $data['location'] ?? null,
                'expected_count' => $data['expected_count'] ?? null,
                'created_by' => $actorId,
            ]);

            $this->outbox->record(
                'inventory.campaign_created',
                'inventory_campaign',
                $campaign->id,
                [
                    'campaign_id' => $campaign->id,
                    'institution_id' => $campaign->institution_id,
                    'designation' => $campaign->designation,
                    'type' => $campaign->type->value,
                    'period_start' => $campaign->period_start->toDateString(),
                    'period_end' => $campaign->period_end->toDateString(),
                    'responsible_account_id' => $campaign->responsible_account_id,
                ],
                correlationId: $correlationId,
                institutionId: $institutionId,
                actor: $actor ?? ($actorId ? ['type' => 'user', 'id' => $actorId] : null),
            );

            return $campaign;
        });
    }

    /**
     * @param  array{type?:string,id?:string}|null  $actor
     */
    public function changeStatus(InventoryCampaign $campaign, CampaignStatus $target, ?string $motif = null, ?array $actor = null, ?string $correlationId = null): InventoryCampaign
    {
        return DB::transaction(function () use ($campaign, $target, $motif, $actor, $correlationId) {
            $campaign = InventoryCampaign::lockForUpdate()->findOrFail($campaign->id);
            $previous = $campaign->status;

            if (! $previous->canTransitionTo($target)) {
                throw new DomainException(
                    'CAMPAIGN_INVALID_TRANSITION',
                    "Transition {$previous->value} → {$target->value} non autorisee.",
                );
            }

            if ($target === CampaignStatus::Closed && $campaign->pendingFindingsCount() > 0) {
                throw new DomainException(
                    'CAMPAIGN_PENDING_FINDINGS',
                    'Impossible de cloturer : des constats attendent le rapprochement controle.',
                );
            }

            $campaign->status = $target;
            $campaign->save();

            $this->outbox->record(
                'inventory.campaign_status_changed',
                'inventory_campaign',
                $campaign->id,
                [
                    'campaign_id' => $campaign->id,
                    'institution_id' => $campaign->institution_id,
                    'status' => $target->value,
                    'previous_status' => $previous->value,
                    'motif' => $motif,
                ],
                correlationId: $correlationId,
                institutionId: $campaign->institution_id,
                actor: $actor,
            );

            return $campaign;
        });
    }

    /**
     * @param  array{type?:string,id?:string}|null  $actor
     */
    public function assignAgent(InventoryCampaign $campaign, string $functionalAccountId, string $assignedBy, ?array $actor = null, ?string $correlationId = null): InventoryCampaignAgent
    {
        if (in_array($campaign->status, [CampaignStatus::Closed, CampaignStatus::Cancelled], true)) {
            throw new DomainException('CAMPAIGN_NOT_ASSIGNABLE', 'Campagne cloturee ou annulee : affectation impossible.');
        }

        return DB::transaction(function () use ($campaign, $functionalAccountId, $assignedBy, $actor, $correlationId) {
            $existing = $campaign->agents()
                ->where('functional_account_id', $functionalAccountId)
                ->active()
                ->first();

            if ($existing) {
                throw new DomainException('CAMPAIGN_AGENT_ALREADY_ASSIGNED', 'Cet agent est deja affecte a la campagne.');
            }

            $agent = $campaign->agents()->create([
                'functional_account_id' => $functionalAccountId,
                'assigned_by' => $assignedBy,
                'assigned_at' => now(),
            ]);

            $this->outbox->record(
                'inventory.agent_assigned',
                'inventory_campaign',
                $campaign->id,
                [
                    'campaign_id' => $campaign->id,
                    'institution_id' => $campaign->institution_id,
                    'agent_id' => $agent->id,
                    'functional_account_id' => $functionalAccountId,
                ],
                correlationId: $correlationId,
                institutionId: $campaign->institution_id,
                actor: $actor,
            );

            return $agent;
        });
    }

    /**
     * @param  array{type?:string,id?:string}|null  $actor
     */
    public function unassignAgent(InventoryCampaign $campaign, InventoryCampaignAgent $agent, ?string $motif = null, ?string $revokedBy = null, ?array $actor = null, ?string $correlationId = null): void
    {
        if ($agent->campaign_id !== $campaign->id) {
            throw new DomainException('CAMPAIGN_AGENT_MISMATCH', 'Agent non affecte a cette campagne.');
        }

        if (! $agent->isActive()) {
            throw new DomainException('CAMPAIGN_AGENT_ALREADY_REVOKED', 'Agent deja desaffecte.');
        }

        DB::transaction(function () use ($agent, $motif, $revokedBy, $campaign, $actor, $correlationId) {
            $agent->revoked_at = now();
            $agent->revoked_by = $revokedBy;
            $agent->save();

            $this->outbox->record(
                'inventory.agent_unassigned',
                'inventory_campaign',
                $campaign->id,
                [
                    'campaign_id' => $campaign->id,
                    'institution_id' => $campaign->institution_id,
                    'agent_id' => $agent->id,
                    'functional_account_id' => $agent->functional_account_id,
                    'motif' => $motif,
                ],
                correlationId: $correlationId,
                institutionId: $campaign->institution_id,
                actor: $actor,
            );
        });
    }
}
