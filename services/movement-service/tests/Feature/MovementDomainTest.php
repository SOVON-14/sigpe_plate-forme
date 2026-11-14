<?php

namespace Tests\Feature;

use App\Enums\CustodyRole;
use App\Enums\HolderType;
use App\Enums\OperationStatus;
use App\Enums\SharedRightStatus;
use App\Exceptions\DomainException;
use App\Models\CustodyRecord;
use App\Models\OutboxMessage;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MovementDomainTest extends TestCase
{
    use RefreshDatabase;

    private MovementService $service;

    private string $institutionId;

    private string $destinationId;

    private string $assetId;

    private string $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MovementService::class);
        $this->institutionId = (string) Str::uuid7();
        $this->destinationId = (string) Str::uuid7();
        $this->assetId = (string) Str::uuid7();
        $this->accountId = (string) Str::uuid7();
    }

    private function lastEvent(): ?OutboxMessage
    {
        return OutboxMessage::orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function test_assign_and_close_custody(): void
    {
        $custody = $this->service->assignCustody(
            $this->assetId, $this->institutionId,
            HolderType::Account, $this->accountId,
            CustodyRole::PhysicalCustodian,
            $this->accountId,
            actor: ['type' => 'user', 'id' => $this->accountId],
        );

        $this->assertNull($custody->valid_to);
        $this->assertSame('movement.custody.assigned', $this->lastEvent()->event_type);

        // Nouvelle affectation du meme role → cloture de l'ancienne
        $custody2 = $this->service->assignCustody(
            $this->assetId, $this->institutionId,
            HolderType::OrganizationalUnit, (string) Str::uuid7(),
            CustodyRole::PhysicalCustodian,
            $this->accountId,
        );

        $this->assertNotNull($custody->fresh()->valid_to);
        $this->assertNull($custody2->valid_to);
        $this->assertSame(1, CustodyRecord::where('asset_id', $this->assetId)->current()->count());

        // Double cloture refusee
        $this->service->closeCustody($custody2, 'Fin de mission', $this->accountId);
        $this->expectException(DomainException::class);
        $this->service->closeCustody($custody2->fresh(), 'Encore', $this->accountId);
    }

    public function test_internal_transfer_full_flow(): void
    {
        $op = $this->service->createOperation([
            'type' => 'internal_transfer',
            'origin_unit_id' => (string) Str::uuid7(),
            'destination_unit_id' => (string) Str::uuid7(),
            'motif' => 'Reaffectation service',
        ], $this->institutionId, [$this->assetId], $this->accountId);

        // Interne sans workflow → demarre en pending_departure
        $this->assertSame(OperationStatus::PendingDeparture, $op->status);

        $op = $this->service->confirmDeparture($op, ['comment' => 'Bien verifie'], $this->accountId);
        $this->assertSame(OperationStatus::PendingReception, $op->status);

        $op = $this->service->confirmReception($op, ['received_state_note' => 'Bon etat'], $this->accountId);
        $this->assertSame(OperationStatus::Completed, $op->status);

        // Garde transferee + evenement
        $custody = CustodyRecord::where('asset_id', $this->assetId)->current()->first();
        $this->assertSame(HolderType::Institution, $custody->holder_type);
        $this->assertSame('movement.internal_transfer.completed', $this->lastEvent()->event_type);
    }

    public function test_internal_transfer_rejects_foreign_destination(): void
    {
        $this->expectException(DomainException::class);
        $this->service->createOperation([
            'type' => 'internal_transfer',
            'destination_institution_id' => $this->destinationId,
        ], $this->institutionId, [$this->assetId], $this->accountId);
    }

    public function test_interministerial_tec017_never_completes_before_asset(): void
    {
        $op = $this->service->createOperation([
            'type' => 'interministerial_transfer',
            'destination_institution_id' => $this->destinationId,
            'motif' => 'Transfert ministeriel',
        ], $this->institutionId, [$this->assetId, (string) Str::uuid7()], $this->accountId);

        // Soumis au workflow → pending_validation + evenement requested
        $op = $this->service->submitToWorkflow($op, ['workflow_instance_id' => (string) Str::uuid7()], $this->accountId);
        $this->assertSame(OperationStatus::PendingValidation, $op->status);
        $this->assertSame('movement.interministerial_transfer.requested', $this->lastEvent()->event_type);

        // Approbation workflow → pending_departure
        $op = $this->service->handleWorkflowOutcome($op->workflow_instance_id, 'approved', $this->accountId);
        $this->assertSame(OperationStatus::PendingDeparture, $op->status);

        $op = $this->service->confirmDeparture($op, [], $this->accountId);
        $op = $this->service->confirmReception($op, [], $this->accountId);

        // TEC-017 : pending_asset_confirmation, JAMAIS completed
        $this->assertSame(OperationStatus::PendingAssetConfirmation, $op->status);
        $this->assertFalse(
            OutboxMessage::where('event_type', 'movement.interministerial_transfer.completed')->exists(),
        );

        // Annulation interdite a ce stade
        try {
            $this->service->cancelOperation($op, 'Trop tard', $this->accountId);
            $this->fail('Annulation autorisee pendant pending_asset_confirmation');
        } catch (DomainException $e) {
            $this->assertSame('OPERATION_ASSET_CONFIRMATION_PENDING', $e->errorCode);
        }

        // Confirmation partielle → toujours pas completed
        [$a1, $a2] = $op->assetIds();
        $op = $this->service->recordAssetRattachementConfirmation($op, [$a1 => 'PAT-NEW-000001']);
        $this->assertSame(OperationStatus::PendingAssetConfirmation, $op->status);

        // Confirmation complete → completed + evenement + gardes transferees
        $op = $this->service->recordAssetRattachementConfirmation($op, [$a2 => 'PAT-NEW-000002']);
        $this->assertSame(OperationStatus::Completed, $op->status);
        $this->assertSame('movement.interministerial_transfer.completed', $this->lastEvent()->event_type);
        $this->assertSame(
            2,
            CustodyRecord::where('asset_id', $a1)->current()->where('institution_id', $this->destinationId)->count()
                + CustodyRecord::where('asset_id', $a2)->current()->where('institution_id', $this->destinationId)->count(),
        );
    }

    public function test_interministerial_rejected_by_workflow(): void
    {
        $wf = (string) Str::uuid7();
        $op = $this->service->createOperation([
            'type' => 'interministerial_transfer',
            'destination_institution_id' => $this->destinationId,
            'workflow_id' => $wf,
        ], $this->institutionId, [$this->assetId], $this->accountId);
        $op = $op->fresh();

        $this->assertSame(OperationStatus::PendingValidation, $op->status);

        $op = $this->service->handleWorkflowOutcome($wf, 'rejected', $this->accountId, comment: 'Refuse');

        $this->assertSame(OperationStatus::Rejected, $op->status);
        $this->assertSame('Refuse', $op->decision_comment);
    }

    public function test_loan_lifecycle_activation_return_and_late(): void
    {
        $op = $this->service->createOperation([
            'type' => 'loan',
            'destination_institution_id' => $this->destinationId,
            'loan_start_at' => now()->subDay()->toIso8601String(),
            'loan_end_at' => now()->subHour()->toIso8601String(), // deja echéance → is_late
            'custodian_account_id' => $this->accountId,
            'convention_ref' => 'CONV-001',
        ], $this->institutionId, [$this->assetId], $this->accountId);

        $this->assertSame(OperationStatus::Draft, $op->status);
        $this->assertSame('movement.loan.requested', $this->lastEvent()->event_type);

        // Approbation → actif + loan.activated
        $wf = (string) Str::uuid7();
        $op->forceFill(['workflow_instance_id' => $wf])->save();
        $op = $this->service->submitToWorkflow($op->fresh(), [], $this->accountId);
        $op = $this->service->handleWorkflowOutcome($wf, 'approved', $this->accountId);

        $this->assertSame(OperationStatus::Active, $op->status);
        $this->assertSame('movement.loan.activated', $this->lastEvent()->event_type);

        // Restitution → returned + is_late=true
        $op = $this->service->returnLoan($op, [], $this->accountId);
        $this->assertSame(OperationStatus::Returned, $op->status);
        $this->assertSame('movement.loan.returned', $this->lastEvent()->event_type);
        $this->payload = $this->lastEvent()->payload;
        $this->assertTrue($this->lastEvent()->payload['is_late']);
    }

    public function test_loan_requires_borrower_and_end_date(): void
    {
        try {
            $this->service->createOperation([
                'type' => 'loan',
                'destination_institution_id' => $this->institutionId,
                'loan_end_at' => now()->addMonth()->toIso8601String(),
            ], $this->institutionId, [$this->assetId], $this->accountId);
            $this->fail('Pret sans emprunteur accepte');
        } catch (DomainException $e) {
            $this->assertSame('LOAN_SAME_INSTITUTION', $e->errorCode);
        }
    }

    public function test_incident_blocks_return_and_cancel(): void
    {
        $op = $this->service->createOperation([
            'type' => 'loan',
            'destination_institution_id' => $this->destinationId,
            'loan_end_at' => now()->addMonth()->toIso8601String(),
        ], $this->institutionId, [$this->assetId], $this->accountId);
        $wf = (string) Str::uuid7();
        $op->forceFill(['workflow_instance_id' => $wf])->save();
        $op = $this->service->submitToWorkflow($op->fresh(), [], $this->accountId);
        $op = $this->service->handleWorkflowOutcome($wf, 'approved', $this->accountId);

        $op = $this->service->reportIncident($op, [
            'incident_type' => 'theft',
            'description' => 'Bien vole pendant le pret, declaration immediate',
        ], $this->accountId);
        $this->assertSame(OperationStatus::IncidentReported, $op->status);

        $this->expectException(DomainException::class);
        $this->service->returnLoan($op->fresh(), [], $this->accountId);
    }

    public function test_assignment_direct_creates_custody(): void
    {
        $op = $this->service->createOperation([
            'type' => 'assignment',
            'holder_type' => 'account',
            'holder_ref' => $this->accountId,
            'custody_role' => 'physical_custodian',
            'motif' => 'Affectation initiale',
        ], $this->institutionId, [$this->assetId], $this->accountId);

        $this->assertSame(OperationStatus::Completed, $op->status);
        $custody = CustodyRecord::where('asset_id', $this->assetId)->current()->first();
        $this->assertSame($this->accountId, $custody->holder_ref);
        $this->assertSame(CustodyRole::PhysicalCustodian, $custody->custody_role);
    }

    public function test_shared_right_lifecycle(): void
    {
        $right = $this->service->createSharedRight([
            'asset_id' => $this->assetId,
            'responsible_institution_id' => $this->institutionId,
            'beneficiary_institution_id' => $this->destinationId,
            'usage_scope' => 'Usage partage local technique',
            'convention_ref' => 'CONV-2026-009',
        ], $this->accountId);

        $this->assertSame(SharedRightStatus::Active, $right->status);
        $this->assertSame('movement.shared_right.granted', $this->lastEvent()->event_type);

        // Doublon actif refuse
        try {
            $this->service->createSharedRight([
                'asset_id' => $this->assetId,
                'responsible_institution_id' => $this->institutionId,
                'beneficiary_institution_id' => $this->destinationId,
                'usage_scope' => 'Doublon',
            ], $this->accountId);
            $this->fail('Double droit actif accepte');
        } catch (DomainException $e) {
            $this->assertSame('SHARED_RIGHT_ALREADY_ACTIVE', $e->errorCode);
        }

        $right = $this->service->transitionSharedRight($right, SharedRightStatus::Suspended, 'Litige en cours', $this->accountId);
        $this->assertSame(SharedRightStatus::Suspended, $right->status);
        $right = $this->service->transitionSharedRight($right, SharedRightStatus::Terminated, 'Arbitrage rendu', $this->accountId);
        $this->assertSame(SharedRightStatus::Terminated, $right->status);
        $this->assertSame('terminated', $this->lastEvent()->payload['status']);
    }

    public function test_cancel_rules(): void
    {
        $op = $this->service->createOperation([
            'type' => 'loan',
            'destination_institution_id' => $this->destinationId,
            'loan_end_at' => now()->addMonth()->toIso8601String(),
        ], $this->institutionId, [$this->assetId], $this->accountId);

        $op = $this->service->cancelOperation($op, 'Demande retiree', $this->accountId);
        $this->assertSame(OperationStatus::Cancelled, $op->status);

        $this->expectException(DomainException::class);
        $this->service->cancelOperation($op->fresh(), 'Encore', $this->accountId);
    }
}
