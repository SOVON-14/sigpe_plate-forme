<?php

namespace App\Services;

use App\Enums\CustodyRole;
use App\Enums\HolderType;
use App\Enums\IncidentType;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Enums\SharedRightStatus;
use App\Exceptions\DomainException;
use App\Jobs\RequestAssetRattachement;
use App\Models\CustodyRecord;
use App\Models\MovementHistory;
use App\Models\MovementOperation;
use App\Models\SharedRight;
use Illuminate\Support\Facades\DB;

class MovementService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    // ------------------------------------------------------------------
    // Gardes / affectations
    // ------------------------------------------------------------------

    public function assignCustody(
        string $assetId,
        string $institutionId,
        HolderType $holderType,
        string $holderRef,
        CustodyRole $custodyRole,
        string $createdBy,
        ?string $validFrom = null,
        ?string $sourceOperationId = null,
        ?array $actor = null,
        ?string $correlationId = null,
        ?string $motif = null,
    ): CustodyRecord {
        return DB::transaction(function () use ($assetId, $institutionId, $holderType, $holderRef, $custodyRole, $createdBy, $validFrom, $sourceOperationId, $actor, $correlationId, $motif) {
            // Une seule garde courante par (asset_id, custody_role) :
            // la precedente est cloturee avant la nouvelle affectation.
            CustodyRecord::where('asset_id', $assetId)
                ->where('custody_role', $custodyRole->value)
                ->current()
                ->update(['valid_to' => now()]);

            $custody = CustodyRecord::create([
                'asset_id' => $assetId,
                'institution_id' => $institutionId,
                'holder_type' => $holderType,
                'holder_ref' => $holderRef,
                'custody_role' => $custodyRole,
                'valid_from' => $validFrom ?? now(),
                'source_operation_id' => $sourceOperationId,
                'created_by' => $createdBy,
            ]);

            $this->history('custody', $custody->id, 'assigned', null, $custody->only([
                'asset_id', 'institution_id', 'holder_type', 'holder_ref', 'custody_role',
            ]), $actor, $motif, $correlationId);

            $this->outbox->record(
                'movement.custody.assigned',
                'custody_record',
                $custody->id,
                [
                    'custody_id' => $custody->id,
                    'asset_id' => $assetId,
                    'institution_id' => $institutionId,
                    'holder_type' => $holderType->value,
                    'holder_ref' => $holderRef,
                    'custody_role' => $custodyRole->value,
                    'valid_from' => $custody->valid_from->toIso8601String(),
                    'source_operation_id' => $sourceOperationId,
                ],
                correlationId: $correlationId,
                institutionId: $institutionId,
                actor: $actor,
            );

            return $custody;
        });
    }

    public function closeCustody(CustodyRecord $custody, string $motif, string $closedBy, ?array $actor = null, ?string $correlationId = null): CustodyRecord
    {
        if ($custody->valid_to !== null) {
            throw new DomainException('CUSTODY_ALREADY_CLOSED', 'Cette garde est deja cloturee.');
        }

        return DB::transaction(function () use ($custody, $motif, $actor, $correlationId) {
            $before = $custody->only(['asset_id', 'holder_type', 'holder_ref', 'custody_role', 'valid_to']);
            $custody->forceFill(['valid_to' => $custody->valid_to ?? now()])->save();

            $this->history('custody', $custody->id, 'closed', $before, $custody->only([
                'asset_id', 'holder_type', 'holder_ref', 'custody_role', 'valid_to',
            ]), $actor, $motif, $correlationId);

            return $custody->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Operations
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $assetIds
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function createOperation(
        array $data,
        string $institutionId,
        array $assetIds,
        string $createdBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): MovementOperation {
        $type = OperationType::from($data['type']);

        $this->assertTypeRules($type, $data, $institutionId);

        $requireWorkflow = ($data['require_workflow'] ?? false) === true || isset($data['workflow_id']);

        return DB::transaction(function () use ($data, $type, $institutionId, $assetIds, $createdBy, $actor, $correlationId, $requireWorkflow) {
            // Transfert interne : pas de circuit requis par defaut —
            // il demarre directement en attente de confirmation de
            // sortie (CDC §4.7). Les autres types demarrent en draft.
            $initialStatus = $type === OperationType::InternalTransfer && ! $requireWorkflow
                ? OperationStatus::PendingDeparture
                : OperationStatus::Draft;

            $operation = MovementOperation::create([
                'type' => $type,
                'status' => $initialStatus,
                'institution_id' => $institutionId,
                'destination_institution_id' => $data['destination_institution_id'] ?? null,
                'origin_unit_id' => $data['origin_unit_id'] ?? null,
                'destination_unit_id' => $data['destination_unit_id'] ?? null,
                'operation_ref' => $data['operation_ref'] ?? null,
                'workflow_instance_id' => $data['workflow_instance_id'] ?? $data['workflow_id'] ?? null,
                'motif' => $data['motif'] ?? null,
                'loan_start_at' => $data['loan_start_at'] ?? null,
                'loan_end_at' => $data['loan_end_at'] ?? null,
                'custodian_account_id' => $data['custodian_account_id'] ?? null,
                'convention_ref' => $data['convention_ref'] ?? null,
                'conditions' => $data['conditions'] ?? null,
                'holder_type' => $data['holder_type'] ?? null,
                'holder_ref' => $data['holder_ref'] ?? null,
                'custody_role' => $data['custody_role'] ?? null,
                'created_by' => $createdBy,
            ]);

            foreach ($assetIds as $assetId) {
                $operation->assets()->create([
                    'asset_id' => $assetId,
                    'patrimonial_code' => $data['patrimonial_codes'][$assetId] ?? null,
                ]);
            }

            $this->history('operation', $operation->id, 'created', null, [
                'type' => $type->value,
                'status' => $initialStatus->value,
                'asset_ids' => $assetIds,
            ], $actor, $data['motif'] ?? null, $correlationId);

            // Pret : evenement de demande des la creation.
            if ($type === OperationType::Loan) {
                $this->emitLoanRequested($operation, $actor, $correlationId);
            }

            // Affectation sans circuit : effet immediat (garde creee).
            if ($type === OperationType::Assignment && ! $requireWorkflow) {
                $this->activateAssignment($operation, $actor, $correlationId);
            }

            // Soumission immediate si un circuit est fourni.
            if ($requireWorkflow) {
                $this->submitToWorkflow($operation, $data, $createdBy, $actor, $correlationId);
            }

            return $operation->fresh('assets');
        });
    }

    /**
     * Soumet l'operation a un circuit Workflow (ou marque pending_validation
     * en attendant le rattachement d'une instance externe via operation_ref).
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function submitToWorkflow(
        MovementOperation $operation,
        array $data,
        string $submittedBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): MovementOperation {
        if ($operation->status !== OperationStatus::Draft) {
            throw new DomainException('OPERATION_NOT_SUBMITTABLE', 'Seule une operation en brouillon peut etre soumise.');
        }

        return DB::transaction(function () use ($operation, $data, $actor, $correlationId) {
            $before = $operation->only(['status', 'workflow_instance_id']);

            $operation->forceFill([
                'status' => OperationStatus::PendingValidation,
                'workflow_instance_id' => $data['workflow_instance_id'] ?? $operation->workflow_instance_id,
            ])->save();

            $this->history('operation', $operation->id, 'submitted', $before, $operation->only([
                'status', 'workflow_instance_id',
            ]), $actor, $data['motif'] ?? null, $correlationId);

            if ($operation->type === OperationType::InterministerialTransfer) {
                $this->outbox->record(
                    'movement.interministerial_transfer.requested',
                    'movement_operation',
                    $operation->id,
                    [
                        'operation_id' => $operation->id,
                        'origin_institution_id' => $operation->institution_id,
                        'destination_institution_id' => $operation->destination_institution_id,
                        'asset_ids' => $operation->assetIds(),
                        'workflow_instance_id' => $operation->workflow_instance_id,
                        'motif' => $operation->motif,
                    ],
                    correlationId: $correlationId,
                    institutionId: $operation->institution_id,
                    actor: $actor,
                );
            }

            return $operation->fresh('assets');
        });
    }

    /**
     * Point d'entree du consommateur workflow.instance_completed.
     * Applique l'outcome a l'operation rattachee a l'instance.
     *
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function handleWorkflowOutcome(
        string $workflowInstanceId,
        string $outcome,
        ?string $decidedBy = null,
        ?array $actor = null,
        ?string $correlationId = null,
        ?string $comment = null,
    ): ?MovementOperation {
        $operation = MovementOperation::where('workflow_instance_id', $workflowInstanceId)->first();
        if ($operation === null) {
            return null;
        }

        return match ($outcome) {
            'approved' => $this->onWorkflowApproved($operation, $decidedBy, $actor, $correlationId),
            'rejected' => $this->onWorkflowRejected($operation, $decidedBy, $comment, $actor, $correlationId),
            'cancelled' => $this->cancelOperation($operation, 'Circuit annule', $decidedBy ?? 'system', $actor, $correlationId),
            'correction_requested' => $this->onWorkflowCorrection($operation, $actor, $correlationId),
            default => throw new DomainException('WORKFLOW_OUTCOME_UNKNOWN', 'Outcome workflow inconnu : '.$outcome),
        };
    }

    private function onWorkflowApproved(MovementOperation $operation, ?string $decidedBy, ?array $actor, ?string $correlationId): MovementOperation
    {
        return DB::transaction(function () use ($operation, $decidedBy, $actor, $correlationId) {
            $before = $operation->only(['status']);

            $next = match ($operation->type) {
                OperationType::Assignment => $this->activateAssignment($operation, $actor, $correlationId),
                OperationType::Loan => $this->activateLoan($operation, $actor, $correlationId),
                default => OperationStatus::PendingDeparture,
            };

            if ($next !== null) {
                $operation->forceFill([
                    'status' => $next,
                    'decided_by' => $decidedBy,
                    'decided_at' => now(),
                ])->save();
            }

            $this->history('operation', $operation->id, 'workflow_approved', $before, $operation->only(['status']), $actor, null, $correlationId);

            return $operation->fresh('assets');
        });
    }

    private function onWorkflowRejected(MovementOperation $operation, ?string $decidedBy, ?string $comment, ?array $actor, ?string $correlationId): MovementOperation
    {
        return DB::transaction(function () use ($operation, $decidedBy, $comment, $actor, $correlationId) {
            $before = $operation->only(['status']);
            $operation->forceFill([
                'status' => OperationStatus::Rejected,
                'decided_by' => $decidedBy,
                'decided_at' => now(),
                'decision_comment' => $comment,
            ])->save();

            $this->history('operation', $operation->id, 'workflow_rejected', $before, $operation->only(['status']), $actor, $comment, $correlationId);

            return $operation->fresh('assets');
        });
    }

    private function onWorkflowCorrection(MovementOperation $operation, ?array $actor, ?string $correlationId): MovementOperation
    {
        return DB::transaction(function () use ($operation, $actor, $correlationId) {
            $before = $operation->only(['status']);
            $operation->forceFill(['status' => OperationStatus::Draft])->save();
            $this->history('operation', $operation->id, 'workflow_correction', $before, $operation->only(['status']), $actor, null, $correlationId);

            return $operation->fresh('assets');
        });
    }

    /**
     * Confirmation de sortie par l'unite d'origine (transferts).
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function confirmDeparture(MovementOperation $operation, array $data, string $confirmedBy, ?array $actor = null, ?string $correlationId = null): MovementOperation
    {
        $this->assertTransfer($operation);
        $this->assertStatus($operation, OperationStatus::PendingDeparture, 'OPERATION_NOT_AWAITING_DEPARTURE');

        return DB::transaction(function () use ($operation, $data, $confirmedBy, $actor, $correlationId) {
            $before = $operation->only(['status', 'departure_confirmed_by']);
            $operation->forceFill([
                'status' => OperationStatus::PendingReception,
                'departure_confirmed_by' => $confirmedBy,
                'departure_confirmed_at' => now(),
            ])->save();

            $this->history('operation', $operation->id, 'departure_confirmed', $before, $operation->only([
                'status', 'departure_confirmed_by', 'departure_confirmed_at',
            ]), $actor, $data['comment'] ?? null, $correlationId);

            return $operation->fresh('assets');
        });
    }

    /**
     * Confirmation de reception par la destination.
     * Interministerial : passe en pending_asset_confirmation (TEC-017) —
     * la cloture exige asset.rattachement_confirmed via
     * recordAssetRattachementConfirmation().
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function confirmReception(MovementOperation $operation, array $data, string $confirmedBy, ?array $actor = null, ?string $correlationId = null): MovementOperation
    {
        $this->assertTransfer($operation);
        $this->assertStatus($operation, OperationStatus::PendingReception, 'OPERATION_NOT_AWAITING_RECEPTION');

        $result = DB::transaction(function () use ($operation, $data, $confirmedBy, $actor, $correlationId) {
            $before = $operation->only(['status', 'reception_confirmed_by']);
            $next = $operation->isInterministerial()
                ? OperationStatus::PendingAssetConfirmation
                : OperationStatus::Completed;

            $operation->forceFill([
                'status' => $next,
                'reception_confirmed_by' => $confirmedBy,
                'reception_confirmed_at' => now(),
            ])->save();

            $this->history('operation', $operation->id, 'reception_confirmed', $before, $operation->only([
                'status', 'reception_confirmed_by', 'reception_confirmed_at',
            ]), $actor, ($data['comment'] ?? null).'|state:'.($data['received_state_note'] ?? ''), $correlationId);

            if (! $operation->isInterministerial()) {
                $this->completeInternalTransfer($operation, $actor, $correlationId);
            }

            return $operation->fresh('assets');
        });

        // TEC-017 : apres commit, demander a Asset le changement de
        // rattachement pour chaque bien. L'operation reste
        // pending_asset_confirmation jusqu'a asset.rattachement_confirmed.
        if ($result->status === OperationStatus::PendingAssetConfirmation) {
            foreach ($result->assetIds() as $assetId) {
                RequestAssetRattachement::dispatch($result->id, $assetId);
            }
        }

        return $result;
    }

    /**
     * TEC-017 — consomme asset.rattachement_confirmed.
     * Attend la confirmation de TOUS les biens avant de cloturer.
     * Idempotent : une confirmation deja enregistree est ignoree.
     *
     * @param  array<string, mixed>  $confirmations  asset_id => nouveau code patrimonial
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function recordAssetRattachementConfirmation(
        MovementOperation $operation,
        array $confirmations,
        ?array $actor = null,
        ?string $correlationId = null,
    ): MovementOperation {
        if (! $operation->isInterministerial()) {
            throw new DomainException('OPERATION_NOT_INTERMINISTERIAL', 'Seul un transfert interministeriel attend une confirmation Asset.');
        }
        $this->assertStatus($operation, OperationStatus::PendingAssetConfirmation, 'OPERATION_NOT_AWAITING_ASSET');

        return DB::transaction(function () use ($operation, $confirmations, $actor, $correlationId) {
            $known = $operation->assetIds();
            foreach ($confirmations as $assetId => $code) {
                if (! in_array($assetId, $known, true)) {
                    throw new DomainException('ASSET_NOT_IN_OPERATION', "Le bien {$assetId} ne fait pas partie de l'operation.");
                }
            }

            $existing = $operation->asset_confirmations ?? [];
            $merged = $existing + $confirmations;

            $operation->forceFill([
                'asset_confirmations' => $merged,
                'asset_confirmed_at' => count($merged) >= count($known) ? now() : null,
            ])->save();

            $this->history('operation', $operation->id, 'asset_rattachement_partial', null, [
                'confirmed' => array_keys($merged),
                'expected' => $known,
            ], $actor, null, $correlationId);

            // Regle absolue TEC-017 : cloture seulement quand tous les
            // biens sont confirmes par Asset.
            if (count($merged) >= count($known)) {
                $operation->forceFill(['status' => OperationStatus::Completed])->save();
                $this->history('operation', $operation->id, 'completed', null, [
                    'status' => OperationStatus::Completed->value,
                    'new_patrimonial_codes' => $merged,
                ], $actor, null, $correlationId);

                // Transfert definitif : la garde passe a l'institution
                // destinataire pour chaque bien.
                foreach ($known as $assetId) {
                    $this->transferCustodyToInstitution(
                        $assetId,
                        $operation->destination_institution_id,
                        $operation->id,
                        $actor,
                        $correlationId,
                    );
                }

                $this->outbox->record(
                    'movement.interministerial_transfer.completed',
                    'movement_operation',
                    $operation->id,
                    [
                        'operation_id' => $operation->id,
                        'origin_institution_id' => $operation->institution_id,
                        'destination_institution_id' => $operation->destination_institution_id,
                        'asset_ids' => $known,
                        'new_patrimonial_codes' => $merged,
                        'completed_at' => now()->toIso8601String(),
                    ],
                    correlationId: $correlationId,
                    institutionId: $operation->institution_id,
                    actor: $actor,
                );
            }

            return $operation->fresh('assets');
        });
    }

    /**
     * Restitution d'un pret.
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function returnLoan(MovementOperation $operation, array $data, string $returnedBy, ?array $actor = null, ?string $correlationId = null): MovementOperation
    {
        if ($operation->type !== OperationType::Loan) {
            throw new DomainException('OPERATION_NOT_LOAN', 'Seul un pret peut etre restitue.');
        }
        $this->assertStatus($operation, OperationStatus::Active, 'OPERATION_NOT_ACTIVE');

        return DB::transaction(function () use ($operation, $data, $actor, $correlationId) {
            $isLate = $operation->loan_end_at !== null && now()->greaterThan($operation->loan_end_at);
            $before = $operation->only(['status']);

            $operation->forceFill([
                'status' => OperationStatus::Returned,
                'returned_at' => now(),
            ])->save();

            $this->history('operation', $operation->id, 'loan_returned', $before, $operation->only([
                'status', 'returned_at',
            ]), $actor, $data['comment'] ?? null, $correlationId);

            // La garde revient au preteur (destination_institution_id
            // = institution lateinit pour un pret).
            foreach ($operation->assetIds() as $assetId) {
                $this->transferCustodyToInstitution(
                    $assetId,
                    $operation->destination_institution_id ?? $operation->institution_id,
                    $operation->id,
                    $actor,
                    $correlationId,
                );
            }

            $this->emitLoanReturned($operation, $isLate, $actor, $correlationId);

            return $operation->fresh('assets');
        });
    }

    /**
     * Declaration d'incident (perte, vol, dommage) pendant un pret.
     * Ouvre la procedure contradictoire et bloque les changements
     * sensibles (restitution/annulation refuseses en incident_reported).
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function reportIncident(MovementOperation $operation, array $data, string $reportedBy, ?array $actor = null, ?string $correlationId = null): MovementOperation
    {
        if ($operation->type !== OperationType::Loan) {
            throw new DomainException('OPERATION_NOT_LOAN', 'Un incident ne se declare que sur un pret.');
        }
        if (! in_array($operation->status, [OperationStatus::Active, OperationStatus::ReturnPending], true)) {
            throw new DomainException('OPERATION_NOT_ACTIVE', 'Aucun incident ne peut etre declare sur cette operation.');
        }

        return DB::transaction(function () use ($operation, $data, $actor, $correlationId) {
            $before = $operation->only(['status']);
            $operation->forceFill([
                'status' => OperationStatus::IncidentReported,
                'incident_type' => IncidentType::from($data['incident_type'])->value,
                'incident_description' => $data['description'],
                'incident_evidence_refs' => $data['evidence_refs'] ?? [],
            ])->save();

            $this->history('operation', $operation->id, 'incident_reported', $before, $operation->only([
                'status', 'incident_type', 'incident_description',
            ]), $actor, $data['description'], $correlationId);

            return $operation->fresh('assets');
        });
    }

    /**
     * Annulation d'une operation non terminee.
     *
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function cancelOperation(MovementOperation $operation, string $motif, string $cancelledBy, ?array $actor = null, ?string $correlationId = null): MovementOperation
    {
        if (! $operation->status->isOpen()) {
            throw new DomainException('OPERATION_NOT_CANCELLABLE', 'Operation deja cloturee.');
        }
        // TEC-017 : une fois la confirmation Asset attendue, la
        // cloture est engagee — annulation interdite.
        if ($operation->status === OperationStatus::PendingAssetConfirmation) {
            throw new DomainException('OPERATION_ASSET_CONFIRMATION_PENDING', 'Cloture Asset en cours — annulation impossible.');
        }
        if ($operation->status === OperationStatus::IncidentReported) {
            throw new DomainException('OPERATION_INCIDENT_OPEN', 'Un incident est en cours — resolution requise avant annulation.');
        }

        return DB::transaction(function () use ($operation, $motif, $actor, $correlationId) {
            $before = $operation->only(['status']);
            $operation->forceFill(['status' => OperationStatus::Cancelled])->save();
            $this->history('operation', $operation->id, 'cancelled', $before, $operation->only(['status']), $actor, $motif, $correlationId);

            return $operation->fresh('assets');
        });
    }

    // ------------------------------------------------------------------
    // Affectation (operation type=assignment)
    // ------------------------------------------------------------------

    private function activateAssignment(MovementOperation $operation, ?array $actor, ?string $correlationId): ?OperationStatus
    {
        // L'affectation cree la garde designee puis se complete.
        $holderType = HolderType::tryFrom((string) $operation->holder_type) ?? HolderType::OrganizationalUnit;
        $holderRef = $operation->holder_ref ?? $operation->destination_unit_id ?? $operation->institution_id;
        $custodyRole = CustodyRole::tryFrom((string) $operation->custody_role) ?? CustodyRole::PhysicalCustodian;

        foreach ($operation->assets as $link) {
            $this->assignCustody(
                $link->asset_id,
                $operation->institution_id,
                $holderType,
                (string) $holderRef,
                $custodyRole,
                $operation->created_by,
                null,
                $operation->id,
                $actor,
                $correlationId,
                $operation->motif,
            );
        }

        $operation->forceFill(['status' => OperationStatus::Completed])->save();

        return null; // statut deja positionne
    }

    private function activateLoan(MovementOperation $operation, ?array $actor, ?string $correlationId): ?OperationStatus
    {
        // Pret actif : la garde physique passe a l'emprunteur
        // (institution a l'origine de la demande) ; aucune
        // modification de propriete ni d'identifiant (CDC §4.7).
        foreach ($operation->assetIds() as $assetId) {
            $this->transferCustodyToInstitution(
                $assetId,
                $operation->institution_id,
                $operation->id,
                $actor,
                $correlationId,
            );
        }

        $this->emitLoanActivated($operation, $actor, $correlationId);

        return OperationStatus::Active;
    }

    // ------------------------------------------------------------------
    // Transferts internes
    // ------------------------------------------------------------------

    private function completeInternalTransfer(MovementOperation $operation, ?array $actor, ?string $correlationId): void
    {
        $destination = $operation->destination_institution_id ?? $operation->institution_id;
        foreach ($operation->assetIds() as $assetId) {
            $this->transferCustodyToInstitution($assetId, $destination, $operation->id, $actor, $correlationId);
        }

        $this->history('operation', $operation->id, 'completed', null, ['status' => 'completed'], $actor, null, $correlationId);

        $this->outbox->record(
            'movement.internal_transfer.completed',
            'movement_operation',
            $operation->id,
            [
                'operation_id' => $operation->id,
                'institution_id' => $operation->institution_id,
                'asset_ids' => $operation->assetIds(),
                'origin_unit_id' => $operation->origin_unit_id,
                'destination_unit_id' => $operation->destination_unit_id,
                'completed_at' => now()->toIso8601String(),
            ],
            correlationId: $correlationId,
            institutionId: $operation->institution_id,
            actor: $actor,
        );
    }

    /**
     * Deplace les gardes courantes d'un bien vers une institution
     * (ou une unite) en cloturant les precedentes.
     */
    private function transferCustodyToInstitution(
        string $assetId,
        ?string $institutionId,
        string $operationId,
        ?array $actor,
        ?string $correlationId,
    ): void {
        $current = CustodyRecord::where('asset_id', $assetId)->current()->get();
        foreach ($current as $custody) {
            $before = $custody->only(['institution_id', 'holder_type', 'holder_ref']);
            $custody->forceFill(['valid_to' => now()])->save();
            $this->history('custody', $custody->id, 'closed', $before, $custody->only(['valid_to']), $actor, 'Transfert de garde', $correlationId);
        }

        if ($institutionId === null) {
            return;
        }

        $this->assignCustody(
            $assetId,
            $institutionId,
            HolderType::Institution,
            $institutionId,
            CustodyRole::AdministrativeResponsible,
            'system',
            null,
            $operationId,
            $actor,
            $correlationId,
            'Transfert de rattachement',
        );
    }

    // ------------------------------------------------------------------
    // Biens partages
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function createSharedRight(array $data, string $createdBy, ?array $actor = null, ?string $correlationId = null): SharedRight
    {
        return DB::transaction(function () use ($data, $createdBy, $actor, $correlationId) {
            // Unicite fonctionnelle : pas deux droits actifs pour le
            // meme couple (bien, beneficiaire).
            $exists = SharedRight::where('asset_id', $data['asset_id'])
                ->where('beneficiary_institution_id', $data['beneficiary_institution_id'])
                ->where('status', SharedRightStatus::Active)
                ->exists();
            if ($exists) {
                throw new DomainException('SHARED_RIGHT_ALREADY_ACTIVE', 'Un droit actif existe deja pour ce beneficiaire.');
            }

            $right = SharedRight::create([
                'asset_id' => $data['asset_id'],
                'responsible_institution_id' => $data['responsible_institution_id'],
                'beneficiary_institution_id' => $data['beneficiary_institution_id'],
                'usage_scope' => $data['usage_scope'],
                'responsibilities' => $data['responsibilities'] ?? null,
                'valid_from' => $data['valid_from'] ?? now(),
                'valid_to' => $data['valid_to'] ?? null,
                'convention_ref' => $data['convention_ref'] ?? null,
                'status' => SharedRightStatus::Active,
                'created_by' => $createdBy,
            ]);

            $this->outbox->record(
                'movement.shared_right.granted',
                'shared_right',
                $right->id,
                [
                    'shared_right_id' => $right->id,
                    'asset_id' => $right->asset_id,
                    'responsible_institution_id' => $right->responsible_institution_id,
                    'beneficiary_institution_id' => $right->beneficiary_institution_id,
                    'usage_scope' => $right->usage_scope,
                    'valid_from' => $right->valid_from->toIso8601String(),
                    'valid_to' => $right->valid_to?->toIso8601String(),
                    'convention_ref' => $right->convention_ref,
                ],
                correlationId: $correlationId,
                institutionId: $right->responsible_institution_id,
                actor: $actor,
            );

            return $right;
        });
    }

    /**
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function transitionSharedRight(SharedRight $right, SharedRightStatus $target, string $motif, string $changedBy, ?array $actor = null, ?string $correlationId = null): SharedRight
    {
        $allowed = [
            SharedRightStatus::Active->value => [SharedRightStatus::Suspended, SharedRightStatus::Terminated],
            SharedRightStatus::Suspended->value => [SharedRightStatus::Active, SharedRightStatus::Terminated],
        ];

        $current = $right->status->value;
        if (! in_array($target, $allowed[$current] ?? [], true)) {
            throw new DomainException('SHARED_RIGHT_INVALID_TRANSITION', "Transition {$current} -> {$target->value} interdite.");
        }

        return DB::transaction(function () use ($right, $target, $motif, $actor, $correlationId) {
            $before = $right->only(['status']);
            $right->forceFill([
                'status' => $target,
                'valid_to' => $target === SharedRightStatus::Terminated ? now() : $right->valid_to,
            ])->save();

            $this->history('shared_right', $right->id, 'status_'.$target->value, $before, $right->only(['status']), $actor, $motif, $correlationId);

            $this->outbox->record(
                'movement.shared_right.terminated',
                'shared_right',
                $right->id,
                [
                    'shared_right_id' => $right->id,
                    'asset_id' => $right->asset_id,
                    'responsible_institution_id' => $right->responsible_institution_id,
                    'beneficiary_institution_id' => $right->beneficiary_institution_id,
                    'status' => $target->value,
                    'motif' => $motif,
                    'occurred_at' => now()->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $right->responsible_institution_id,
                actor: $actor,
            );

            return $right->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Regles de validation par type d'operation (CDC §4.7).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertTypeRules(OperationType $type, array $data, string $institutionId): void
    {
        match ($type) {
            OperationType::InternalTransfer => $this->assertInternalTransferRules($data, $institutionId),
            OperationType::InterministerialTransfer => $this->assertInterministerialRules($data, $institutionId),
            OperationType::Loan => $this->assertLoanRules($data, $institutionId),
            OperationType::Assignment => null,
        };
    }

    /** @param  array<string, mixed>  $data */
    private function assertInternalTransferRules(array $data, string $institutionId): void
    {
        // Transfert interne : la destination ne peut pas etre une
        // autre institution (sinon c'est un transfert interministeriel).
        if (isset($data['destination_institution_id'])
            && $data['destination_institution_id'] !== $institutionId) {
            throw new DomainException('INTERNAL_TRANSFER_FOREIGN_DESTINATION', 'Un transfert interne doit rester dans la meme institution.');
        }
    }

    /** @param  array<string, mixed>  $data */
    private function assertInterministerialRules(array $data, string $institutionId): void
    {
        if (empty($data['destination_institution_id'])) {
            throw new DomainException('DESTINATION_REQUIRED', 'Le transfert interministeriel exige une institution destinataire.');
        }
        if ($data['destination_institution_id'] === $institutionId) {
            throw new DomainException('INTERMINISTERIAL_SAME_INSTITUTION', 'Origine et destination identiques — utiliser un transfert interne.');
        }
    }

    /** @param  array<string, mixed>  $data */
    private function assertLoanRules(array $data, string $institutionId): void
    {
        if (empty($data['destination_institution_id'])) {
            throw new DomainException('BORROWER_REQUIRED', 'Le pret exige une institution emprunteuse.');
        }
        if ($data['destination_institution_id'] === $institutionId) {
            throw new DomainException('LOAN_SAME_INSTITUTION', 'Un pret interne n\'est pas un pret interministeriel.');
        }
        if (empty($data['loan_end_at'])) {
            throw new DomainException('LOAN_END_REQUIRED', 'La date d\'echeance du pret est obligatoire.');
        }
        if (isset($data['loan_start_at'], $data['loan_end_at'])
            && strtotime($data['loan_end_at']) <= strtotime($data['loan_start_at'])) {
            throw new DomainException('LOAN_PERIOD_INVALID', 'L\'echeance doit etre posterieure au debut.');
        }
    }

    private function assertTransfer(MovementOperation $operation): void
    {
        if (! in_array($operation->type, [OperationType::InternalTransfer, OperationType::InterministerialTransfer], true)) {
            throw new DomainException('OPERATION_NOT_TRANSFER', 'Cette operation n\'est pas un transfert.');
        }
    }

    private function assertStatus(MovementOperation $operation, OperationStatus $expected, string $code): void
    {
        if ($operation->status !== $expected) {
            throw new DomainException($code, "Statut attendu : {$expected->value}, actuel : {$operation->status->value}.");
        }
    }

    private function emitLoanRequested(MovementOperation $operation, ?array $actor, ?string $correlationId): void
    {
        $this->outbox->record(
            'movement.loan.requested',
            'movement_operation',
            $operation->id,
            [
                'operation_id' => $operation->id,
                'lender_institution_id' => $operation->destination_institution_id,
                'borrower_institution_id' => $operation->institution_id,
                'asset_ids' => $operation->assetIds(),
                'loan_start_at' => $operation->loan_start_at?->toIso8601String(),
                'loan_end_at' => $operation->loan_end_at?->toIso8601String(),
                'custodian_account_id' => $operation->custodian_account_id,
                'convention_ref' => $operation->convention_ref,
                'workflow_instance_id' => $operation->workflow_instance_id,
            ],
            correlationId: $correlationId,
            institutionId: $operation->institution_id,
            actor: $actor,
        );
    }

    private function emitLoanActivated(MovementOperation $operation, ?array $actor, ?string $correlationId): void
    {
        $this->outbox->record(
            'movement.loan.activated',
            'movement_operation',
            $operation->id,
            [
                'operation_id' => $operation->id,
                'lender_institution_id' => $operation->destination_institution_id,
                'borrower_institution_id' => $operation->institution_id,
                'asset_ids' => $operation->assetIds(),
                'loan_start_at' => $operation->loan_start_at?->toIso8601String() ?? now()->toIso8601String(),
                'loan_end_at' => $operation->loan_end_at?->toIso8601String(),
                'custodian_account_id' => $operation->custodian_account_id,
            ],
            correlationId: $correlationId,
            institutionId: $operation->institution_id,
            actor: $actor,
        );
    }

    private function emitLoanReturned(MovementOperation $operation, bool $isLate, ?array $actor, ?string $correlationId): void
    {
        $this->outbox->record(
            'movement.loan.returned',
            'movement_operation',
            $operation->id,
            [
                'operation_id' => $operation->id,
                'lender_institution_id' => $operation->destination_institution_id,
                'borrower_institution_id' => $operation->institution_id,
                'asset_ids' => $operation->assetIds(),
                'returned_at' => $operation->returned_at?->toIso8601String(),
                'is_late' => $isLate,
            ],
            correlationId: $correlationId,
            institutionId: $operation->institution_id,
            actor: $actor,
        );
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array{type?: string, id?: string}|null  $actor
     */
    private function history(
        string $entityType,
        string $entityId,
        string $action,
        ?array $before,
        ?array $after,
        ?array $actor,
        ?string $motif,
        ?string $correlationId,
    ): void {
        MovementHistory::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'actor' => $actor,
            'motif' => $motif,
            'occurred_at' => now(),
        ]);
    }
}
