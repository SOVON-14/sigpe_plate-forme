<?php

namespace App\Services;

use App\Enums\InterventionStatus;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceType;
use App\Enums\PlanStatus;
use App\Enums\ProviderKind;
use App\Enums\StockMovementType;
use App\Exceptions\DomainException;
use App\Models\Intervention;
use App\Models\InterventionPlan;
use App\Models\MaintenanceHistory;
use App\Models\MaintenanceRequest;
use App\Models\SparePart;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierEvaluation;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Moteur metier Maintenance (CDC §4.8) :
 * demandes (preventive/corrective/conditionnelle) -> validation
 * Workflow -> plans preventifs -> interventions (interne/externe)
 * avec couts detailles, prestataires et evaluations, stocks de
 * pieces par magasin avec seuils de reapprovisionnement.
 */
class MaintenanceService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    // ------------------------------------------------------------------
    // Demandes de maintenance
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function createRequest(
        array $data,
        string $institutionId,
        string $createdBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): MaintenanceRequest {
        return DB::transaction(function () use ($data, $institutionId, $createdBy, $actor, $correlationId) {
            $request = MaintenanceRequest::create([
                'reference' => $this->nextReference('MNT'),
                'institution_id' => $institutionId,
                'asset_id' => $data['asset_id'],
                'requested_by_unit_id' => $data['requested_by_unit_id'] ?? null,
                'type' => MaintenanceType::tryFrom((string) $data['type'])
                    ?? throw new DomainException('MAINTENANCE_TYPE_INVALID', 'Type de maintenance invalide.'),
                'description' => $data['description'],
                'urgency' => $data['urgency'] ?? 'normal',
                'symptoms' => $data['symptoms'] ?? null,
                'estimated_cost' => $data['estimated_cost'] ?? null,
                'is_urgent_track' => (bool) ($data['is_urgent_track'] ?? false),
                'urgent_justification' => $data['urgent_justification'] ?? null,
                'status' => MaintenanceRequestStatus::Draft,
                'requester_account_id' => $createdBy,
                'created_by' => $createdBy,
            ]);

            if ($request->is_urgent_track && blank($request->urgent_justification)) {
                throw new DomainException('URGENT_JUSTIFICATION_REQUIRED', 'La voie urgente exige une justification.');
            }

            $this->history('request', $request->id, 'created', null, [
                'reference' => $request->reference,
                'asset_id' => $request->asset_id,
                'type' => $request->type->value,
            ], $actor, null, $correlationId);

            $this->outbox->record(
                'maintenance.request.created',
                'maintenance_request',
                $request->id,
                [
                    'request_id' => $request->id,
                    'reference' => $request->reference,
                    'institution_id' => $request->institution_id,
                    'asset_id' => $request->asset_id,
                    'type' => $request->type->value,
                    'urgency' => $request->urgency,
                    'is_urgent_track' => $request->is_urgent_track,
                    'requester_account_id' => $request->requester_account_id,
                    'created_at' => $request->created_at->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $request->institution_id,
                actor: $actor,
            );

            return $request;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function submitToWorkflow(
        MaintenanceRequest $request,
        array $data,
        string $submittedBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): MaintenanceRequest {
        return DB::transaction(function () use ($request, $data, $submittedBy, $actor, $correlationId) {
            if ($request->status !== MaintenanceRequestStatus::Draft) {
                throw new DomainException('REQUEST_NOT_SUBMITTABLE', 'Seule une demande en brouillon peut etre soumise.');
            }

            // Voie urgente justifiee : la demande est validee
            // immediatement et reste soumise a un controle a
            // posteriori (historique + decision Workflow ulterieure
            // possible). Jamais d'approbation automatique sans trace.
            if ($request->is_urgent_track) {
                $before = $request->only(['status']);
                $request->forceFill([
                    'status' => MaintenanceRequestStatus::Validated,
                    'decided_by' => $submittedBy,
                    'decided_at' => now(),
                    'decision_comment' => 'Voie urgente — controle a posteriori requis.',
                ])->save();

                $this->history('request', $request->id, 'validated_urgent', $before, $request->only(['status']), $actor, $request->urgent_justification, $correlationId);

                $this->outbox->record(
                    'maintenance.request.validated',
                    'maintenance_request',
                    $request->id,
                    [
                        'request_id' => $request->id,
                        'reference' => $request->reference,
                        'institution_id' => $request->institution_id,
                        'asset_id' => $request->asset_id,
                        'type' => $request->type->value,
                        'decided_by' => $submittedBy,
                        'decided_at' => now()->toIso8601String(),
                        'urgent_track' => true,
                    ],
                    correlationId: $correlationId,
                    institutionId: $request->institution_id,
                    actor: $actor,
                );

                return $request->refresh();
            }

            $request->forceFill([
                'status' => MaintenanceRequestStatus::PendingValidation,
                'workflow_instance_id' => $data['workflow_instance_id'] ?? null,
            ])->save();

            $this->history('request', $request->id, 'submitted', null, [
                'status' => $request->status->value,
                'workflow_instance_id' => $request->workflow_instance_id,
            ], $actor, null, $correlationId);

            $this->outbox->record(
                'maintenance.request.submitted',
                'maintenance_request',
                $request->id,
                [
                    'request_id' => $request->id,
                    'reference' => $request->reference,
                    'institution_id' => $request->institution_id,
                    'workflow_instance_id' => $request->workflow_instance_id,
                    'submitted_by' => $submittedBy,
                ],
                correlationId: $correlationId,
                institutionId: $request->institution_id,
                actor: $actor,
            );

            return $request->refresh();
        });
    }

    /**
     * Resultat du circuit Workflow (evenement workflow.instance_completed).
     *
     * @param  array{outcome: string, decided_by?: string|null, decision_comment?: string|null}  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function handleWorkflowOutcome(
        string $workflowInstanceId,
        string $outcome,
        ?string $decidedBy = null,
        ?string $decisionComment = null,
        ?array $actor = null,
        ?string $correlationId = null,
    ): ?MaintenanceRequest {
        return DB::transaction(function () use ($workflowInstanceId, $outcome, $decidedBy, $decisionComment, $actor, $correlationId) {
            $request = MaintenanceRequest::where('workflow_instance_id', $workflowInstanceId)->first();
            if ($request === null) {
                return null;
            }
            if ($request->status !== MaintenanceRequestStatus::PendingValidation) {
                return $request;
            }

            $before = $request->only(['status']);

            $newStatus = match ($outcome) {
                'approved' => MaintenanceRequestStatus::Validated,
                'rejected' => MaintenanceRequestStatus::Rejected,
                'cancelled' => MaintenanceRequestStatus::Cancelled,
                'correction_requested' => MaintenanceRequestStatus::Draft,
                default => throw new DomainException('WORKFLOW_OUTCOME_UNKNOWN', "Outcome Workflow inconnu : {$outcome}"),
            };

            $request->forceFill([
                'status' => $newStatus,
                'decided_by' => $decidedBy,
                'decided_at' => now(),
                'decision_comment' => $decisionComment,
            ])->save();

            $this->history('request', $request->id, 'workflow_'.$outcome, $before, $request->only(['status']), $actor, $decisionComment, $correlationId);

            if ($outcome === 'approved') {
                $this->outbox->record(
                    'maintenance.request.validated',
                    'maintenance_request',
                    $request->id,
                    [
                        'request_id' => $request->id,
                        'reference' => $request->reference,
                        'institution_id' => $request->institution_id,
                        'asset_id' => $request->asset_id,
                        'type' => $request->type->value,
                        'decided_by' => $decidedBy,
                        'decided_at' => now()->toIso8601String(),
                    ],
                    correlationId: $correlationId,
                    institutionId: $request->institution_id,
                    actor: $actor,
                );
            } elseif (in_array($outcome, ['rejected', 'cancelled'], true)) {
                $this->outbox->record(
                    'maintenance.request.rejected',
                    'maintenance_request',
                    $request->id,
                    [
                        'request_id' => $request->id,
                        'reference' => $request->reference,
                        'institution_id' => $request->institution_id,
                        'outcome' => $outcome,
                        'decision_comment' => $decisionComment,
                    ],
                    correlationId: $correlationId,
                    institutionId: $request->institution_id,
                    actor: $actor,
                );
            }

            return $request->refresh();
        });
    }

    /**
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function cancelRequest(
        MaintenanceRequest $request,
        string $motif,
        ?array $actor = null,
        ?string $correlationId = null,
    ): MaintenanceRequest {
        return DB::transaction(function () use ($request, $motif, $actor, $correlationId) {
            if (in_array($request->status, [
                MaintenanceRequestStatus::Completed,
                MaintenanceRequestStatus::Cancelled,
                MaintenanceRequestStatus::Rejected,
            ], true)) {
                throw new DomainException('REQUEST_NOT_CANCELLABLE', 'Cette demande ne peut plus etre annulee.');
            }

            $before = $request->only(['status']);
            $request->forceFill(['status' => MaintenanceRequestStatus::Cancelled])->save();

            $this->history('request', $request->id, 'cancelled', $before, $request->only(['status']), $actor, $motif, $correlationId);

            return $request->refresh();
        });
    }

    // ------------------------------------------------------------------
    // Plans de maintenance preventive
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function createPlan(
        array $data,
        string $institutionId,
        string $createdBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): InterventionPlan {
        $trigger = $data['trigger'] ?? [];
        if (($trigger['kind'] ?? null) === null) {
            throw new DomainException('PLAN_TRIGGER_REQUIRED', 'Le declencheur du plan est requis (date, kilometrage, heures, cycles, etat).');
        }

        return DB::transaction(function () use ($data, $trigger, $institutionId, $createdBy, $actor, $correlationId) {
            $plan = InterventionPlan::create([
                'institution_id' => $institutionId,
                'asset_id' => $data['asset_id'],
                'type' => MaintenanceType::tryFrom((string) $data['type'])
                    ?? throw new DomainException('MAINTENANCE_TYPE_INVALID', 'Type de maintenance invalide.'),
                'description' => $data['description'],
                'trigger' => $trigger,
                'status' => PlanStatus::Active,
                'assigned_supplier_id' => $data['assigned_supplier_id'] ?? null,
                'estimated_cost' => $data['estimated_cost'] ?? null,
                'next_due_at' => $trigger['next_due_at'] ?? null,
                'created_by' => $createdBy,
            ]);

            $this->history('plan', $plan->id, 'created', null, [
                'asset_id' => $plan->asset_id,
                'trigger' => $plan->trigger,
            ], $actor, null, $correlationId);

            $this->outbox->record(
                'maintenance.plan.scheduled',
                'intervention_plan',
                $plan->id,
                [
                    'plan_id' => $plan->id,
                    'institution_id' => $plan->institution_id,
                    'asset_id' => $plan->asset_id,
                    'type' => $plan->type->value,
                    'trigger' => $plan->trigger,
                    'next_due_at' => $plan->next_due_at?->toDateString(),
                ],
                correlationId: $correlationId,
                institutionId: $plan->institution_id,
                actor: $actor,
            );

            return $plan;
        });
    }

    /**
     * Execute un plan : cree l'intervention planifiee et recalcule
     * la prochaine echeance si un intervalle est defini.
     *
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function executePlan(
        InterventionPlan $plan,
        ?string $plannedAt,
        ?array $actor = null,
        ?string $correlationId = null,
    ): Intervention {
        return DB::transaction(function () use ($plan, $plannedAt, $actor, $correlationId) {
            if ($plan->status !== PlanStatus::Active) {
                throw new DomainException('PLAN_NOT_EXECUTABLE', 'Seul un plan actif peut etre execute.');
            }

            $intervention = $this->createInterventionRecord([
                'asset_id' => $plan->asset_id,
                'plan_id' => $plan->id,
                'type' => $plan->type->value,
                'description' => $plan->description,
                'provider_kind' => $plan->assigned_supplier_id !== null ? 'external' : 'internal',
                'supplier_id' => $plan->assigned_supplier_id,
                'planned_at' => $plannedAt,
                'estimated_cost' => $plan->estimated_cost,
            ], $plan->institution_id, $actor?->id ?? 'system', $actor, $correlationId);

            $plan->forceFill(['last_executed_at' => now()])->save();
            $this->reschedulePlan($plan);

            $this->history('plan', $plan->id, 'executed', null, [
                'intervention_id' => $intervention->id,
            ], $actor, null, $correlationId);

            return $intervention;
        });
    }

    // ------------------------------------------------------------------
    // Interventions
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function createIntervention(
        array $data,
        string $institutionId,
        string $createdBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): Intervention {
        if (isset($data['request_id'])) {
            $request = MaintenanceRequest::find($data['request_id']);
            if ($request === null || $request->institution_id !== $institutionId) {
                throw new DomainException('REQUEST_NOT_FOUND', 'Demande de maintenance introuvable.');
            }
            if (! in_array($request->status, [
                MaintenanceRequestStatus::Validated,
                MaintenanceRequestStatus::Planned,
                MaintenanceRequestStatus::InProgress,
            ], true)) {
                throw new DomainException('REQUEST_NOT_INTERVENABLE', 'La demande doit etre validee avant intervention.');
            }
        }

        return DB::transaction(function () use ($data, $institutionId, $createdBy, $actor, $correlationId) {
            $intervention = $this->createInterventionRecord($data, $institutionId, $createdBy, $actor, $correlationId);

            if (isset($data['request_id'])) {
                $request = MaintenanceRequest::findOrFail($data['request_id']);
                $before = $request->only(['status']);
                if ($request->status === MaintenanceRequestStatus::Validated) {
                    $request->forceFill(['status' => MaintenanceRequestStatus::Planned])->save();
                }
                $this->history('request', $request->id, 'intervention_planned', $before, $request->only(['status']), $actor, null, $correlationId);
            }

            return $intervention;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    private function createInterventionRecord(
        array $data,
        string $institutionId,
        string $createdBy,
        ?array $actor,
        ?string $correlationId,
    ): Intervention {
        $providerKind = ProviderKind::tryFrom((string) ($data['provider_kind'] ?? 'internal'))
            ?? throw new DomainException('PROVIDER_KIND_INVALID', 'provider_kind doit etre internal ou external.');

        $supplierId = $data['supplier_id'] ?? null;
        if ($providerKind === ProviderKind::External) {
            if ($supplierId === null) {
                throw new DomainException('SUPPLIER_REQUIRED', 'Une intervention externe exige un prestataire.');
            }
            $supplier = Supplier::find($supplierId);
            if ($supplier === null || ! $supplier->is_active) {
                throw new DomainException('SUPPLIER_NOT_FOUND', 'Prestataire introuvable ou inactif.');
            }
        }

        $intervention = Intervention::create([
            'reference' => $this->nextReference('INT'),
            'institution_id' => $institutionId,
            'request_id' => $data['request_id'] ?? null,
            'plan_id' => $data['plan_id'] ?? null,
            'asset_id' => $data['asset_id'],
            'type' => MaintenanceType::tryFrom((string) $data['type'])
                ?? throw new DomainException('MAINTENANCE_TYPE_INVALID', 'Type de maintenance invalide.'),
            'description' => $data['description'],
            'provider_kind' => $providerKind,
            'supplier_id' => $supplierId,
            'assigned_account_id' => $data['assigned_account_id'] ?? null,
            'status' => InterventionStatus::Planned,
            'planned_at' => $data['planned_at'] ?? null,
            'estimated_cost' => $data['estimated_cost'] ?? null,
            'created_by' => $createdBy,
        ]);

        $this->history('intervention', $intervention->id, 'created', null, [
            'reference' => $intervention->reference,
            'asset_id' => $intervention->asset_id,
            'provider_kind' => $intervention->provider_kind->value,
        ], $actor, null, $correlationId);

        return $intervention;
    }

    /**
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function startIntervention(
        Intervention $intervention,
        ?array $actor = null,
        ?string $correlationId = null,
    ): Intervention {
        return DB::transaction(function () use ($intervention, $actor, $correlationId) {
            if ($intervention->status !== InterventionStatus::Planned) {
                throw new DomainException('INTERVENTION_NOT_STARTABLE', 'Seule une intervention planifiee peut demarrer.');
            }

            $before = $intervention->only(['status']);
            $intervention->forceFill([
                'status' => InterventionStatus::InProgress,
                'started_at' => now(),
            ])->save();

            $this->history('intervention', $intervention->id, 'started', $before, $intervention->only(['status']), $actor, null, $correlationId);

            $this->outbox->record(
                'maintenance.intervention.started',
                'intervention',
                $intervention->id,
                [
                    'intervention_id' => $intervention->id,
                    'reference' => $intervention->reference,
                    'institution_id' => $intervention->institution_id,
                    'asset_id' => $intervention->asset_id,
                    'request_id' => $intervention->request_id,
                    'type' => $intervention->type->value,
                    'provider_kind' => $intervention->provider_kind->value,
                    'supplier_id' => $intervention->supplier_id,
                    'started_at' => $intervention->started_at->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $intervention->institution_id,
                actor: $actor,
            );

            return $intervention->refresh();
        });
    }

    /**
     * Cloture avec couts detailles obligatoires (CDC §4.8) et
     * consommation eventuelle de pieces en stock.
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function completeIntervention(
        Intervention $intervention,
        array $data,
        ?array $actor = null,
        ?string $correlationId = null,
    ): Intervention {
        return DB::transaction(function () use ($intervention, $data, $actor, $correlationId) {
            if (! in_array($intervention->status, [
                InterventionStatus::Planned,
                InterventionStatus::InProgress,
            ], true)) {
                throw new DomainException('INTERVENTION_NOT_COMPLETABLE', 'Cette intervention ne peut pas etre cloturee.');
            }

            $costs = $data['costs'] ?? [];
            foreach (['labor', 'parts', 'supplies', 'transport', 'external_services', 'other'] as $key) {
                if (! isset($costs[$key]) || ! is_numeric($costs[$key]) || $costs[$key] < 0) {
                    throw new DomainException('COSTS_INCOMPLETE', "Le cout detaille exige le champ '{$key}'.");
                }
            }
            if (blank($data['outcome_note'] ?? null)) {
                throw new DomainException('OUTCOME_NOTE_REQUIRED', 'Le compte rendu de fin d intervention est requis.');
            }

            $total = array_sum(array_map('floatval', [
                $costs['labor'], $costs['parts'], $costs['supplies'],
                $costs['transport'], $costs['external_services'], $costs['other'],
            ]));

            $before = $intervention->only(['status']);
            $intervention->forceFill([
                'status' => InterventionStatus::Completed,
                'started_at' => $intervention->started_at ?? now(),
                'completed_at' => now(),
                'cost_labor' => $costs['labor'],
                'cost_parts' => $costs['parts'],
                'cost_supplies' => $costs['supplies'],
                'cost_transport' => $costs['transport'],
                'cost_external_services' => $costs['external_services'],
                'cost_other' => $costs['other'],
                'cost_total' => $total,
                'outcome_note' => $data['outcome_note'],
            ])->save();

            // Consommation des pieces declarees : sortie de stock.
            foreach (($data['parts_used'] ?? []) as $part) {
                $this->consumePart(
                    $part['spare_part_id'],
                    (float) $part['quantity'],
                    $intervention->institution_id,
                    $actor?->id ?? $intervention->created_by,
                    $correlationId,
                    "Consommation intervention {$intervention->reference}",
                );
            }

            // Demande liee : passage a completed.
            if ($intervention->request_id !== null) {
                $request = MaintenanceRequest::find($intervention->request_id);
                if ($request !== null && $request->status !== MaintenanceRequestStatus::Completed) {
                    $reqBefore = $request->only(['status']);
                    $request->forceFill(['status' => MaintenanceRequestStatus::Completed])->save();
                    $this->history('request', $request->id, 'completed', $reqBefore, $request->only(['status']), $actor, null, $correlationId);
                }
            }

            if ($intervention->plan_id !== null) {
                $plan = InterventionPlan::find($intervention->plan_id);
                if ($plan !== null) {
                    $plan->forceFill(['last_executed_at' => now()])->save();
                    $this->reschedulePlan($plan);
                }
            }

            $this->history('intervention', $intervention->id, 'completed', $before, $intervention->only(['status']), $actor, $data['outcome_note'], $correlationId);

            $this->outbox->record(
                'maintenance.intervention.completed',
                'intervention',
                $intervention->id,
                [
                    'intervention_id' => $intervention->id,
                    'reference' => $intervention->reference,
                    'institution_id' => $intervention->institution_id,
                    'asset_id' => $intervention->asset_id,
                    'request_id' => $intervention->request_id,
                    'type' => $intervention->type->value,
                    'provider_kind' => $intervention->provider_kind->value,
                    'supplier_id' => $intervention->supplier_id,
                    'costs' => $intervention->costs(),
                    'outcome_note' => $intervention->outcome_note,
                    'completed_at' => $intervention->completed_at->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $intervention->institution_id,
                actor: $actor,
            );

            return $intervention->refresh();
        });
    }

    /**
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function interruptIntervention(
        Intervention $intervention,
        string $motif,
        ?array $actor = null,
        ?string $correlationId = null,
    ): Intervention {
        return $this->transitionIntervention($intervention, InterventionStatus::Interrupted, 'interrupted', $motif, $actor, $correlationId);
    }

    /**
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function cancelIntervention(
        Intervention $intervention,
        string $motif,
        ?array $actor = null,
        ?string $correlationId = null,
    ): Intervention {
        return DB::transaction(function () use ($intervention, $motif, $actor, $correlationId) {
            if ($intervention->status !== InterventionStatus::Planned) {
                throw new DomainException('INTERVENTION_NOT_CANCELLABLE', 'Seule une intervention planifiee peut etre annulee.');
            }

            $result = $this->transitionIntervention($intervention, InterventionStatus::Cancelled, 'cancelled', $motif, $actor, $correlationId);

            $this->outbox->record(
                'maintenance.intervention.cancelled',
                'intervention',
                $intervention->id,
                [
                    'intervention_id' => $intervention->id,
                    'reference' => $intervention->reference,
                    'institution_id' => $intervention->institution_id,
                    'asset_id' => $intervention->asset_id,
                    'status' => 'cancelled',
                    'motif' => $motif,
                ],
                correlationId: $correlationId,
                institutionId: $intervention->institution_id,
                actor: $actor,
            );

            return $result;
        });
    }

    /**
     * @param  array{type?: string, id?: string}|null  $actor
     */
    private function transitionIntervention(
        Intervention $intervention,
        InterventionStatus $target,
        string $action,
        ?string $motif,
        ?array $actor,
        ?string $correlationId,
    ): Intervention {
        return DB::transaction(function () use ($intervention, $target, $action, $motif, $actor, $correlationId) {
            $allowed = match ($target) {
                InterventionStatus::Interrupted => [InterventionStatus::InProgress],
                InterventionStatus::Cancelled => [InterventionStatus::Planned],
                default => [],
            };

            if (! in_array($intervention->status, $allowed, true)) {
                throw new DomainException('INTERVENTION_INVALID_TRANSITION', "Transition impossible vers {$target->value}.");
            }

            $before = $intervention->only(['status']);
            $intervention->forceFill([
                'status' => $target,
                'motif' => $motif,
            ])->save();

            $this->history('intervention', $intervention->id, $action, $before, $intervention->only(['status']), $actor, $motif, $correlationId);

            if ($target === InterventionStatus::Interrupted) {
                $this->outbox->record(
                    'maintenance.intervention.cancelled',
                    'intervention',
                    $intervention->id,
                    [
                        'intervention_id' => $intervention->id,
                        'reference' => $intervention->reference,
                        'institution_id' => $intervention->institution_id,
                        'asset_id' => $intervention->asset_id,
                        'status' => 'interrupted',
                        'motif' => $motif,
                    ],
                    correlationId: $correlationId,
                    institutionId: $intervention->institution_id,
                    actor: $actor,
                );
            }

            return $intervention->refresh();
        });
    }

    // ------------------------------------------------------------------
    // Prestataires et evaluations
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function createSupplier(
        array $data,
        ?string $institutionId,
        string $createdBy,
        bool $nationalScopeAllowed,
        ?array $actor = null,
        ?string $correlationId = null,
    ): Supplier {
        $scope = $data['scope'] ?? 'ministerial';
        if ($scope === 'national') {
            if (! $nationalScopeAllowed) {
                throw new DomainException('NATIONAL_SCOPE_FORBIDDEN', 'Seule l autorite nationale peut creer un prestataire national.');
            }
            $institutionId = null;
        }

        return DB::transaction(function () use ($data, $institutionId, $createdBy, $actor, $correlationId) {
            $supplier = Supplier::create([
                'institution_id' => $institutionId,
                'scope' => $data['scope'] ?? 'ministerial',
                'name' => $data['name'],
                'specialty' => $data['specialty'],
                'contact_name' => $data['contact_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'address' => $data['address'] ?? null,
                'contract_ref' => $data['contract_ref'] ?? null,
                'contract_end_at' => $data['contract_end_at'] ?? null,
                'is_active' => true,
                'created_by' => $createdBy,
            ]);

            $this->history('supplier', $supplier->id, 'created', null, [
                'name' => $supplier->name,
                'scope' => $supplier->scope,
            ], $actor, null, $correlationId);

            return $supplier;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function evaluateSupplier(
        Supplier $supplier,
        array $data,
        string $institutionId,
        string $createdBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): SupplierEvaluation {
        return DB::transaction(function () use ($supplier, $data, $institutionId, $createdBy, $actor, $correlationId) {
            $intervention = Intervention::find($data['intervention_id'] ?? '');
            if ($intervention === null
                || $intervention->status !== InterventionStatus::Completed
                || $intervention->supplier_id !== $supplier->id) {
                throw new DomainException('INTERVENTION_NOT_EVALUABLE', 'Seule une intervention externe terminee peut etre evaluee.');
            }

            $scores = ['quality', 'timeliness', 'budget', 'parts_quality', 'contract_compliance', 'satisfaction'];
            $provided = array_filter($scores, fn ($k) => isset($data[$k]));
            if ($provided === []) {
                throw new DomainException('EVALUATION_EMPTY', 'Au moins un critere d evaluation est requis.');
            }
            foreach ($provided as $key) {
                $v = (int) $data[$key];
                if ($v < 1 || $v > 5) {
                    throw new DomainException('EVALUATION_SCORE_INVALID', "Le critere '{$key}' doit etre entre 1 et 5.");
                }
            }

            $evaluation = SupplierEvaluation::create([
                'supplier_id' => $supplier->id,
                'institution_id' => $institutionId,
                'intervention_id' => $intervention->id,
                'quality' => $data['quality'] ?? null,
                'timeliness' => $data['timeliness'] ?? null,
                'budget' => $data['budget'] ?? null,
                'parts_quality' => $data['parts_quality'] ?? null,
                'contract_compliance' => $data['contract_compliance'] ?? null,
                'satisfaction' => $data['satisfaction'] ?? null,
                'comment' => $data['comment'] ?? null,
                'created_by' => $createdBy,
            ]);

            $this->history('supplier', $supplier->id, 'evaluated', null, [
                'evaluation_id' => $evaluation->id,
                'intervention_id' => $intervention->id,
                'average' => $evaluation->averageScore(),
            ], $actor, null, $correlationId);

            return $evaluation;
        });
    }

    // ------------------------------------------------------------------
    // Stocks : magasins, pieces, mouvements
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function createWarehouse(
        array $data,
        string $institutionId,
        string $createdBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): Warehouse {
        return DB::transaction(function () use ($data, $institutionId, $createdBy, $actor, $correlationId) {
            $warehouse = Warehouse::create([
                'institution_id' => $institutionId,
                'organizational_unit_id' => $data['organizational_unit_id'] ?? null,
                'name' => $data['name'],
                'location' => $data['location'] ?? null,
                'is_active' => true,
                'created_by' => $createdBy,
            ]);

            $this->history('warehouse', $warehouse->id, 'created', null, [
                'name' => $warehouse->name,
            ], $actor, null, $correlationId);

            return $warehouse;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function createSparePart(
        array $data,
        string $institutionId,
        string $createdBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): SparePart {
        return DB::transaction(function () use ($data, $institutionId, $createdBy, $actor, $correlationId) {
            $part = SparePart::create([
                'institution_id' => $institutionId,
                'reference' => $data['reference'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'unit' => $data['unit'],
                'category_id' => $data['category_id'] ?? null,
                'unit_cost' => $data['unit_cost'] ?? null,
                'reorder_threshold' => $data['reorder_threshold'] ?? null,
                'is_active' => true,
                'created_by' => $createdBy,
            ]);

            $this->history('spare_part', $part->id, 'created', null, [
                'reference' => $part->reference,
                'name' => $part->name,
            ], $actor, null, $correlationId);

            return $part;
        });
    }

    /**
     * Entree, sortie ou transfert de stock. Les niveaux sont ajustes
     * dans la meme transaction ; le seuil de reapprovisionnement
     * declenche maintenance.spare_part.below_threshold.v1.
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function recordStockMovement(
        array $data,
        string $institutionId,
        string $actorId,
        ?array $actor = null,
        ?string $correlationId = null,
    ): StockMovement {
        return DB::transaction(function () use ($data, $institutionId, $actorId, $actor, $correlationId) {
            $type = StockMovementType::tryFrom((string) ($data['type'] ?? ''))
                ?? throw new DomainException('STOCK_MOVEMENT_TYPE_INVALID', 'Type de mouvement invalide (entry, exit, transfer_in, transfer_out).');
            $quantity = (float) ($data['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new DomainException('STOCK_QTY_INVALID', 'La quantite doit etre positive.');
            }

            $part = SparePart::where('institution_id', $institutionId)->find($data['spare_part_id'] ?? '');
            if ($part === null) {
                throw new DomainException('SPARE_PART_NOT_FOUND', 'Piece de rechange introuvable.');
            }

            $warehouse = Warehouse::where('institution_id', $institutionId)->find($data['warehouse_id'] ?? '');
            if ($warehouse === null) {
                throw new DomainException('WAREHOUSE_NOT_FOUND', 'Magasin introuvable.');
            }

            // Transfert : une paire de mouvements atomiques.
            if (in_array($type, [StockMovementType::TransferOut, StockMovementType::TransferIn], true)) {
                if ($type === StockMovementType::TransferOut) {
                    $targetId = $data['target_warehouse_id'] ?? null;
                    $target = $targetId !== null
                        ? Warehouse::where('institution_id', $institutionId)->find($targetId)
                        : null;
                    if ($target === null) {
                        throw new DomainException('TARGET_WAREHOUSE_REQUIRED', 'Le magasin de destination est requis pour un transfert.');
                    }
                } else {
                    throw new DomainException('STOCK_MOVEMENT_TYPE_INVALID', 'Utiliser transfer_out pour initier un transfert.');
                }
            }

            if (in_array($type, [StockMovementType::Exit, StockMovementType::TransferOut], true)) {
                $this->adjustLevel($part->id, $warehouse->id, -$quantity, $institutionId);
            } else {
                $this->adjustLevel($part->id, $warehouse->id, $quantity, $institutionId);
            }

            $movement = StockMovement::create([
                'institution_id' => $institutionId,
                'spare_part_id' => $part->id,
                'warehouse_id' => $warehouse->id,
                'type' => $type,
                'quantity' => $quantity,
                'target_warehouse_id' => $data['target_warehouse_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'reference' => $data['reference'] ?? null,
                'unit_cost' => $data['unit_cost'] ?? null,
                'actor' => $actorId,
                'occurred_at' => now(),
            ]);

            // Cote destination d'un transfert.
            if ($type === StockMovementType::TransferOut) {
                $target = Warehouse::findOrFail($data['target_warehouse_id']);
                $this->adjustLevel($part->id, $target->id, $quantity, $institutionId);

                StockMovement::create([
                    'institution_id' => $institutionId,
                    'spare_part_id' => $part->id,
                    'warehouse_id' => $target->id,
                    'type' => StockMovementType::TransferIn,
                    'quantity' => $quantity,
                    'target_warehouse_id' => $warehouse->id,
                    'reason' => $data['reason'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'unit_cost' => $data['unit_cost'] ?? null,
                    'actor' => $actorId,
                    'occurred_at' => now(),
                ]);
            }

            $this->checkReorderThreshold($part, $warehouse, $actor, $correlationId);

            return $movement;
        });
    }

    // ------------------------------------------------------------------
    // Internes
    // ------------------------------------------------------------------

    /**
     * Sortie de stock pour consommation d'intervention.
     */
    private function consumePart(
        string $sparePartId,
        float $quantity,
        string $institutionId,
        string $actorId,
        ?string $correlationId,
        string $reason,
    ): void {
        $part = SparePart::where('institution_id', $institutionId)->find($sparePartId);
        if ($part === null) {
            throw new DomainException('SPARE_PART_NOT_FOUND', 'Piece de rechange introuvable.');
        }

        // Magasin par defaut : premier magasin actif de l'institution.
        $warehouse = Warehouse::where('institution_id', $institutionId)->where('is_active', true)->first();
        if ($warehouse === null) {
            throw new DomainException('WAREHOUSE_REQUIRED', 'Aucun magasin actif pour consommer la piece.');
        }

        $this->recordStockMovement([
            'spare_part_id' => $part->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'exit',
            'quantity' => $quantity,
            'reason' => $reason,
        ], $institutionId, $actorId, null, $correlationId);
    }

    private function adjustLevel(string $sparePartId, string $warehouseId, float $delta, string $institutionId): void
    {
        $level = StockLevel::where('spare_part_id', $sparePartId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        $current = $level !== null ? (float) $level->quantity : 0.0;
        $next = $current + $delta;
        if ($next < -0.0001) {
            throw new DomainException('INSUFFICIENT_STOCK', 'Stock insuffisant pour cette sortie.');
        }

        if ($level === null) {
            StockLevel::create([
                'spare_part_id' => $sparePartId,
                'warehouse_id' => $warehouseId,
                'quantity' => max(0.0, $next),
            ]);
        } else {
            $level->forceFill(['quantity' => max(0.0, $next)])->save();
        }
    }

    private function checkReorderThreshold(SparePart $part, Warehouse $warehouse, ?array $actor, ?string $correlationId): void
    {
        if ($part->reorder_threshold === null) {
            return;
        }

        $level = StockLevel::where('spare_part_id', $part->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();
        $quantity = $level !== null ? (float) $level->quantity : 0.0;

        if ($quantity < (float) $part->reorder_threshold) {
            $this->outbox->record(
                'maintenance.spare_part.below_threshold',
                'spare_part',
                $part->id,
                [
                    'spare_part_id' => $part->id,
                    'institution_id' => $part->institution_id,
                    'reference' => $part->reference,
                    'name' => $part->name,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => $quantity,
                    'reorder_threshold' => (float) $part->reorder_threshold,
                    'unit' => $part->unit,
                ],
                correlationId: $correlationId,
                institutionId: $part->institution_id,
                actor: $actor,
            );
        }
    }

    /**
     * Recalcule next_due_at a partir de l'intervalle du trigger.
     */
    private function reschedulePlan(InterventionPlan $plan): void
    {
        $trigger = $plan->trigger ?? [];
        $interval = $trigger['interval_value'] ?? null;
        if ($interval === null) {
            return;
        }

        $unit = $trigger['interval_unit'] ?? 'days';
        $base = $plan->next_due_at ?? now();
        $plan->forceFill([
            'next_due_at' => match ($unit) {
                'days' => $base->copy()->addDays((int) $interval),
                'weeks' => $base->copy()->addWeeks((int) $interval),
                'months' => $base->copy()->addMonths((int) $interval),
                'years' => $base->copy()->addYears((int) $interval),
                default => $base->copy()->addDays((int) $interval),
            },
        ])->save();
    }

    private function nextReference(string $prefix): string
    {
        $year = (int) now()->format('Y');
        $sequence = DB::table('maintenance_sequences')
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            DB::table('maintenance_sequences')->insert(['year' => $year, 'last_number' => 1]);
            $number = 1;
        } else {
            $number = $sequence->last_number + 1;
            DB::table('maintenance_sequences')->where('year', $year)->update(['last_number' => $number]);
        }

        return sprintf('%s-%d-%06d', $prefix, $year, $number);
    }

    /**
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
        MaintenanceHistory::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'actor' => $actor,
            'motif' => $motif,
            'correlation_id' => $correlationId,
            'occurred_at' => now(),
        ]);
    }
}
