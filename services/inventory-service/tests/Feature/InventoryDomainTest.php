<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Exceptions\DomainException;
use App\Models\InventoryCampaign;
use App\Models\OutboxMessage;
use App\Services\FindingService;
use App\Services\InventoryCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryDomainTest extends TestCase
{
    use RefreshDatabase;

    private InventoryCampaignService $campaigns;

    private FindingService $findings;

    private string $institutionId;

    private string $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campaigns = app(InventoryCampaignService::class);
        $this->findings = app(FindingService::class);
        $this->institutionId = (string) Str::uuid7();
        $this->accountId = (string) Str::uuid7();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'designation' => 'Inventaire general annuel 2026',
            'type' => CampaignType::GeneralAnnual,
            'period_start' => '2026-01-05',
            'period_end' => '2026-02-28',
            'responsible_account_id' => $this->accountId,
        ], $overrides);
    }

    public function test_create_campaign_emits_event(): void
    {
        $campaign = $this->campaigns->create(
            $this->payload(), $this->institutionId, $this->accountId,
        );

        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertSame('inventory.campaign_created', OutboxMessage::orderByDesc('id')->first()->event_type);
    }

    public function test_invalid_period_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->campaigns->create(
            $this->payload(['period_start' => '2026-03-01', 'period_end' => '2026-02-01']),
            $this->institutionId, $this->accountId,
        );
    }

    public function test_status_transitions_enforced(): void
    {
        $campaign = $this->campaigns->create($this->payload(), $this->institutionId, $this->accountId);

        // draft → under_review interdit directement
        try {
            $this->campaigns->changeStatus($campaign, CampaignStatus::UnderReview);
            $this->fail('Transition invalide acceptee');
        } catch (DomainException $e) {
            $this->assertSame('CAMPAIGN_INVALID_TRANSITION', $e->errorCode);
        }

        $campaign = $this->campaigns->changeStatus($campaign->fresh(), CampaignStatus::InProgress);
        $campaign = $this->campaigns->changeStatus($campaign->fresh(), CampaignStatus::UnderReview);
        $campaign = $this->campaigns->changeStatus($campaign->fresh(), CampaignStatus::InProgress, 'Reouverture');
        $this->assertSame(CampaignStatus::InProgress, $campaign->status);
    }

    public function test_close_blocked_by_pending_findings(): void
    {
        $campaign = $this->startCampaign();
        $this->findings->record($campaign, [
            'result' => 'non_trouve',
            'identification_method' => 'manual',
            'free_reference' => 'BIEN-INCONNU-1',
        ], $this->accountId);

        $campaign = $this->campaigns->changeStatus($campaign->fresh(), CampaignStatus::UnderReview);

        try {
            $this->campaigns->changeStatus($campaign->fresh(), CampaignStatus::Closed);
            $this->fail('Cloture avec constats en attente acceptee');
        } catch (DomainException $e) {
            $this->assertSame('CAMPAIGN_PENDING_FINDINGS', $e->errorCode);
        }
    }

    public function test_finding_requires_reference_without_asset(): void
    {
        $campaign = $this->startCampaign();

        $this->expectException(DomainException::class);
        $this->findings->record($campaign, [
            'result' => 'trouve',
            'identification_method' => 'manual',
        ], $this->accountId);
    }

    public function test_finding_not_recordable_outside_in_progress(): void
    {
        $campaign = $this->campaigns->create($this->payload(), $this->institutionId, $this->accountId);

        $this->expectException(DomainException::class);
        $this->findings->record($campaign, [
            'result' => 'trouve',
            'identification_method' => 'qr_code',
            'asset_id' => (string) Str::uuid7(),
        ], $this->accountId);
    }

    public function test_verify_flow_and_close(): void
    {
        $campaign = $this->startCampaign();
        $assetId = (string) Str::uuid7();

        $finding = $this->findings->record($campaign, [
            'result' => 'trouve',
            'identification_method' => 'qr_code',
            'asset_id' => $assetId,
            'anomaly_type' => 'endommage',
            'location_found' => 'Bureau direction',
        ], $this->accountId);

        $this->assertSame('pending', $finding->verification_status->value);
        $this->assertSame('inventory.finding_recorded', OutboxMessage::orderByDesc('id')->first()->event_type);

        $finding = $this->findings->verify($finding, 'confirmed', 'Anomalie confirmee', $this->accountId);
        $this->assertSame('confirmed', $finding->verification_status->value);
        $this->assertNotNull($finding->verified_at);

        // Double verification refusee
        try {
            $this->findings->verify($finding->fresh(), 'rejected', null, $this->accountId);
            $this->fail('Double verification acceptee');
        } catch (DomainException $e) {
            $this->assertSame('FINDING_ALREADY_VERIFIED', $e->errorCode);
        }

        $campaign = $this->campaigns->changeStatus($campaign->fresh(), CampaignStatus::UnderReview);
        $campaign = $this->campaigns->changeStatus($campaign->fresh(), CampaignStatus::Closed);
        $this->assertSame(CampaignStatus::Closed, $campaign->status);
    }

    public function test_agents_assign_unassign(): void
    {
        $campaign = $this->campaigns->create($this->payload(), $this->institutionId, $this->accountId);
        $agentAccount = (string) Str::uuid7();

        $agent = $this->campaigns->assignAgent($campaign, $agentAccount, $this->accountId);
        $this->assertTrue($agent->isActive());

        try {
            $this->campaigns->assignAgent($campaign->fresh(), $agentAccount, $this->accountId);
            $this->fail('Double affectation acceptee');
        } catch (DomainException $e) {
            $this->assertSame('CAMPAIGN_AGENT_ALREADY_ASSIGNED', $e->errorCode);
        }

        $this->campaigns->unassignAgent($campaign->fresh(), $agent->fresh(), 'Mutation', $this->accountId);
        $this->assertFalse($agent->fresh()->isActive());
        $this->assertSame('inventory.agent_unassigned', OutboxMessage::orderByDesc('id')->first()->event_type);
    }

    private function startCampaign(): InventoryCampaign
    {
        $campaign = $this->campaigns->create($this->payload(), $this->institutionId, $this->accountId);

        return $this->campaigns->changeStatus($campaign, CampaignStatus::InProgress);
    }
}
