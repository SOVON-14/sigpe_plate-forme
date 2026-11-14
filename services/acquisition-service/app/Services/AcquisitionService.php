<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\ReceptionStatus;
use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Enums\ValueKind;
use App\Exceptions\DomainException;
use App\Jobs\RegisterAcceptedItems;
use App\Models\AcquisitionDelivery;
use App\Models\AcquisitionHistory;
use App\Models\AcquisitionReception;
use App\Models\AcquisitionReceptionLine;
use App\Models\AcquisitionRequest;
use Illuminate\Support\Facades\DB;

/**
 * Moteur metier Acquisition (CDC §4.5) :
 * expression de besoin -> validation Workflow -> selection ->
 * commande -> livraisons -> receptions controlees -> enregistrement
 * patrimonial (via Asset Service pour les seuls biens acceptes).
 */
class AcquisitionService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    // ------------------------------------------------------------------
    // Demandes
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
    ): AcquisitionRequest {
        $items = $data['items'] ?? [];
        if ($items === []) {
            throw new DomainException('ITEMS_REQUIRED', 'Au moins une ligne de besoin est requise.');
        }

        return DB::transaction(function () use ($data, $institutionId, $createdBy, $actor, $correlationId, $items) {
            $request = AcquisitionRequest::create([
                'reference' => $this->nextReference(),
                'institution_id' => $institutionId,
                'organizational_unit_id' => $data['organizational_unit_id'] ?? null,
                'requester_account_id' => $createdBy,
                'title' => $data['title'],
                'motif' => $data['motif'] ?? null,
                'priority' => RequestPriority::tryFrom((string) ($data['priority'] ?? 'normal')) ?? RequestPriority::Normal,
                'needed_by' => $data['needed_by'] ?? null,
                'status' => RequestStatus::Draft,
                'created_by' => $createdBy,
            ]);

            $total = 0.0;
            foreach ($items as $item) {
                $request->items()->create([
                    'description' => $item['description'],
                    'specifications' => $item['specifications'] ?? null,
                    'quantity_ordered' => $item['quantity_ordered'],
                    'unit' => $item['unit'] ?? null,
                    'estimated_unit_value' => $item['estimated_unit_value'] ?? null,
                    'value_kind' => ValueKind::Estimate,
                    'value_justification' => $item['value_justification'] ?? null,
                    'category_id' => $item['category_id'] ?? null,
                ]);
                $total += (float) ($item['estimated_unit_value'] ?? 0) * (float) $item['quantity_ordered'];
            }
            $request->forceFill(['total_estimated_value' => $total > 0 ? $total : null])->save();

            $this->history('request', $request->id, 'created', null, [
                'reference' => $request->reference,
                'status' => RequestStatus::Draft->value,
                'item_count' => count($items),
            ], $actor, null, $correlationId);

            $this->outbox->record(
                'acquisition.request.created',
                'acquisition_request',
                $request->id,
                [
                    'request_id' => $request->id,
                    'reference' => $request->reference,
                    'institution_id' => $request->institution_id,
                    'organizational_unit_id' => $request->organizational_unit_id,
                    'requester_account_id' => $request->requester_account_id,
                    'title' => $request->title,
                    'priority' => $request->priority->value,
                    'item_count' => count($items),
                    'total_estimated_value' => $request->total_estimated_value !== null ? (float) $request->total_estimated_value : null,
                    'created_at' => $request->created_at->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $request->institution_id,
                actor: $actor,
            );

            return $request->fresh('items');
        });
    }

    /**
     * Soumission au circuit Workflow de validation.
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function submitToWorkflow(
        AcquisitionRequest $request,
        array $data,
        string $submittedBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): AcquisitionRequest {
        $this->assertStatus($request, RequestStatus::Draft, 'REQUEST_NOT_SUBMITTABLE');

        return DB::transaction(function () use ($request, $data, $submittedBy, $actor, $correlationId) {
            $before = $request->only(['status', 'workflow_instance_id']);
            $request->forceFill([
                'status' => RequestStatus::PendingValidation,
                'workflow_instance_id' => $data['workflow_instance_id'] ?? $request->workflow_instance_id,
            ])->save();

            $this->history('request', $request->id, 'submitted', $before, $request->only([
                'status', 'workflow_instance_id',
            ]), $actor, null, $correlationId);

            $this->outbox->record(
                'acquisition.request.submitted',
                'acquisition_request',
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

            return $request->fresh('items');
        });
    }

    /**
     * Point d'entree du consommateur workflow.instance_completed.
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
    ): ?AcquisitionRequest {
        $request = AcquisitionRequest::where('workflow_instance_id', $workflowInstanceId)->first()
            ?? AcquisitionRequest::find($workflowInstanceId);

        if ($request === null) {
            return null;
        }

        return match ($outcome) {
            'approved' => $this->onWorkflowApproved($request, $decidedBy, $actor, $correlationId),
            'rejected' => $this->onWorkflowRejected($request, $decidedBy, $comment, $actor, $correlationId),
            'cancelled' => $this->cancelRequest($request, 'Circuit annule', $decidedBy ?? 'system', $actor, $correlationId),
            'correction_requested' => $this->onWorkflowCorrection($request, $actor, $correlationId),
            default => throw new DomainException('WORKFLOW_OUTCOME_UNKNOWN', 'Outcome workflow inconnu : '.$outcome),
        };
    }

    private function onWorkflowApproved(AcquisitionRequest $request, ?string $decidedBy, ?array $actor, ?string $correlationId): AcquisitionRequest
    {
        return DB::transaction(function () use ($request, $decidedBy, $actor, $correlationId) {
            $before = $request->only(['status']);
            $request->forceFill([
                'status' => RequestStatus::Validated,
                'decided_by' => $decidedBy,
                'decided_at' => now(),
            ])->save();

            $this->history('request', $request->id, 'workflow_approved', $before, $request->only(['status']), $actor, null, $correlationId);

            return $request->fresh('items');
        });
    }

    private function onWorkflowRejected(AcquisitionRequest $request, ?string $decidedBy, ?string $comment, ?array $actor, ?string $correlationId): AcquisitionRequest
    {
        return DB::transaction(function () use ($request, $decidedBy, $comment, $actor, $correlationId) {
            $before = $request->only(['status']);
            $request->forceFill([
                'status' => RequestStatus::Rejected,
                'decided_by' => $decidedBy,
                'decided_at' => now(),
                'decision_comment' => $comment,
            ])->save();

            $this->history('request', $request->id, 'workflow_rejected', $before, $request->only(['status']), $actor, $comment, $correlationId);

            return $request->fresh('items');
        });
    }

    private function onWorkflowCorrection(AcquisitionRequest $request, ?array $actor, ?string $correlationId): AcquisitionRequest
    {
        return DB::transaction(function () use ($request, $actor, $correlationId) {
            $before = $request->only(['status']);
            $request->forceFill(['status' => RequestStatus::Draft])->save();
            $this->history('request', $request->id, 'workflow_correction', $before, $request->only(['status']), $actor, null, $correlationId);

            return $request->fresh('items');
        });
    }

    // ------------------------------------------------------------------
    // Selection / commande
    // ------------------------------------------------------------------

    /**
     * Selection de l'offre/fournisseur apres validation.
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function selectOffer(
        AcquisitionRequest $request,
        array $data,
        string $selectedBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): AcquisitionRequest {
        $this->assertStatus($request, RequestStatus::Validated, 'REQUEST_NOT_VALIDATED');

        return DB::transaction(function () use ($request, $data, $selectedBy, $actor, $correlationId) {
            $before = $request->only(['status', 'supplier_name', 'supplier_ref', 'contracted_total_value']);
            $request->forceFill([
                'status' => RequestStatus::Selected,
                'supplier_name' => $data['supplier_name'],
                'supplier_ref' => $data['supplier_ref'] ?? null,
                'contracted_total_value' => $data['contracted_total_value'] ?? null,
            ])->save();

            // La valeur contractuelle globale est tracee sur la demande ;
            // les estimations par ligne sont conservees (value_kind=estimate)
            // jusqu'a ce que Finance integre les valeurs du marche (CDC §4.4).

            $this->history('request', $request->id, 'offer_selected', $before, $request->only([
                'status', 'supplier_name', 'supplier_ref', 'contracted_total_value',
            ]), $actor, $data['justification'] ?? null, $correlationId);

            $this->outbox->record(
                'acquisition.request.selected',
                'acquisition_request',
                $request->id,
                [
                    'request_id' => $request->id,
                    'reference' => $request->reference,
                    'institution_id' => $request->institution_id,
                    'supplier_name' => $request->supplier_name,
                    'supplier_ref' => $request->supplier_ref,
                    'order_ref' => $request->order_ref,
                    'contracted_total_value' => $request->contracted_total_value !== null ? (float) $request->contracted_total_value : null,
                    'selected_by' => $selectedBy,
                ],
                correlationId: $correlationId,
                institutionId: $request->institution_id,
                actor: $actor,
            );

            return $request->fresh('items');
        });
    }

    /**
     * Emission de la commande au fournisseur selectionne.
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function placeOrder(
        AcquisitionRequest $request,
        array $data,
        string $orderedBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): AcquisitionRequest {
        $this->assertStatus($request, RequestStatus::Selected, 'REQUEST_NOT_SELECTED');

        return DB::transaction(function () use ($request, $data, $orderedBy, $actor, $correlationId) {
            $before = $request->only(['status', 'order_ref']);
            $request->forceFill([
                'status' => RequestStatus::Ordered,
                'order_ref' => $data['order_ref'] ?? $request->order_ref,
            ])->save();

            $this->history('request', $request->id, 'ordered', $before, $request->only([
                'status', 'order_ref',
            ]), $actor, null, $correlationId);

            $this->outbox->record(
                'acquisition.request.ordered',
                'acquisition_request',
                $request->id,
                [
                    'request_id' => $request->id,
                    'reference' => $request->reference,
                    'institution_id' => $request->institution_id,
                    'order_ref' => $request->order_ref,
                    'supplier_name' => $request->supplier_name,
                    'ordered_by' => $orderedBy,
                    'ordered_at' => now()->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $request->institution_id,
                actor: $actor,
            );

            return $request->fresh('items');
        });
    }

    // ------------------------------------------------------------------
    // Livraisons / receptions
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function recordDelivery(
        AcquisitionRequest $request,
        array $data,
        string $createdBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): AcquisitionDelivery {
        if (! $request->status->canReceive()) {
            throw new DomainException('REQUEST_NOT_RECEIVABLE', 'Aucune livraison ne peut etre enregistree sur cette demande.');
        }

        return DB::transaction(function () use ($request, $data, $createdBy, $actor, $correlationId) {
            $delivery = AcquisitionDelivery::create([
                'acquisition_request_id' => $request->id,
                'delivery_note_ref' => $data['delivery_note_ref'] ?? null,
                'carrier' => $data['carrier'] ?? null,
                'expected_at' => $data['expected_at'] ?? null,
                'status' => DeliveryStatus::Expected,
                'created_by' => $createdBy,
            ]);

            $this->history('delivery', $delivery->id, 'created', null, [
                'request_id' => $request->id,
                'delivery_note_ref' => $delivery->delivery_note_ref,
            ], $actor, null, $correlationId);

            $this->outbox->record(
                'acquisition.delivery.recorded',
                'acquisition_delivery',
                $delivery->id,
                [
                    'delivery_id' => $delivery->id,
                    'request_id' => $request->id,
                    'reference' => $request->reference,
                    'institution_id' => $request->institution_id,
                    'delivery_note_ref' => $delivery->delivery_note_ref,
                    'carrier' => $delivery->carrier,
                    'expected_at' => $delivery->expected_at?->toIso8601String(),
                    'status' => $delivery->status->value,
                ],
                correlationId: $correlationId,
                institutionId: $request->institution_id,
                actor: $actor,
            );

            return $delivery;
        });
    }

    /**
     * Reception controlee : quantites, specifications, etat, numeros
     * de serie, reserves, non-conformites. Seules les quantites
     * acceptees seront transmises a Asset (job asynchrone).
     *
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function recordReception(
        AcquisitionDelivery $delivery,
        array $data,
        string $receivedBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): AcquisitionReception {
        $request = $delivery->request;
        if (! $request->status->canReceive()) {
            throw new DomainException('REQUEST_NOT_RECEIVABLE', 'La demande ne peut plus recevoir de livraison.');
        }
        if ($delivery->status === DeliveryStatus::Cancelled || $delivery->status === DeliveryStatus::FullyReceived) {
            throw new DomainException('DELIVERY_NOT_RECEIVABLE', 'Cette livraison ne peut plus etre rejetee en reception.');
        }

        $lines = $data['lines'] ?? [];
        if ($lines === []) {
            throw new DomainException('RECEPTION_LINES_REQUIRED', 'Au moins une ligne de reception est requise.');
        }

        $result = DB::transaction(function () use ($delivery, $request, $data, $receivedBy, $actor, $correlationId, $lines) {
            $itemIds = $request->items->pluck('id')->all();
            foreach ($lines as $line) {
                if (! in_array($line['item_id'] ?? null, $itemIds, true)) {
                    throw new DomainException('ITEM_NOT_IN_REQUEST', 'La ligne ne appartient pas a cette demande.');
                }
            }

            $reception = AcquisitionReception::create([
                'delivery_id' => $delivery->id,
                'acquisition_request_id' => $request->id,
                'received_by' => $receivedBy,
                'received_by_name' => $data['received_by_name'] ?? null,
                'status' => ReceptionStatus::Partial,
                'created_by' => $receivedBy,
            ]);

            $qtyAcceptedTotal = 0.0;
            $qtyRefusedTotal = 0.0;

            foreach ($lines as $lineData) {
                $item = $request->items->firstWhere('id', $lineData['item_id']);
                $received = (float) $lineData['quantity_received'];
                $accepted = (float) ($lineData['quantity_accepted'] ?? ($lineData['conform'] ?? true ? $received : 0));
                $refused = (float) ($lineData['quantity_refused'] ?? max(0, $received - $accepted));

                if ($received <= 0) {
                    throw new DomainException('RECEPTION_QTY_INVALID', 'La quantite recue doit etre positive pour chaque ligne.');
                }
                if ($accepted + $refused > $received + 0.0001) {
                    throw new DomainException('RECEPTION_QTY_INVALID', 'acceptee + refusee ne peut depasser la quantite recue.');
                }
                if ($accepted > $item->remainingToAccept() + 0.0001) {
                    throw new DomainException('RECEPTION_OVER_ACCEPTED', 'Quantite acceptee superieure au reste a accepter.');
                }

                $lineModel = $reception->lines()->create([
                    'item_id' => $item->id,
                    'quantity_received' => $received,
                    'quantity_accepted' => $accepted,
                    'quantity_refused' => $refused,
                    'conform' => (bool) ($lineData['conform'] ?? ($refused == 0)),
                    'serial_numbers' => $lineData['serial_numbers'] ?? null,
                    'physical_state_note' => $lineData['physical_state_note'] ?? null,
                    'reserves' => $lineData['reserves'] ?? null,
                    'non_conformity_reason' => $lineData['non_conformity_reason'] ?? null,
                ]);

                $item->increment('quantity_received', $received);
                $item->increment('quantity_accepted', $accepted);
                $item->refresh();

                $qtyAcceptedTotal += $accepted;
                $qtyRefusedTotal += $refused;

                if ($accepted > 0) {
                    $this->outbox->record(
                        'acquisition.item_accepted',
                        'acquisition_reception_line',
                        $lineModel->id,
                        [
                            'request_id' => $request->id,
                            'reference' => $request->reference,
                            'item_id' => $item->id,
                            'reception_id' => $reception->id,
                            'institution_id' => $request->institution_id,
                            'organizational_unit_id' => $request->organizational_unit_id,
                            'description' => $item->description,
                            'specifications' => $item->specifications,
                            'quantity_accepted' => $accepted,
                            'unit' => $item->unit,
                            'serial_numbers' => $lineData['serial_numbers'] ?? [],
                            'category_id' => $item->category_id,
                            'contracted_unit_value' => $item->estimated_unit_value !== null ? (float) $item->estimated_unit_value : null,
                        ],
                        correlationId: $correlationId,
                        institutionId: $request->institution_id,
                        actor: $actor,
                    );
                }
            }

            // Statut de la reception : complete si plus rien a recevoir.
            $request->refresh();
            $allReceived = $request->items->every(fn ($i) => $i->remainingToReceive() <= 0.0001);
            $reception->forceFill(['status' => $allReceived ? ReceptionStatus::Complete : ReceptionStatus::Partial])->save();

            // Statut de la livraison et de la demande.
            $delivery->forceFill([
                'status' => $allReceived ? DeliveryStatus::FullyReceived : DeliveryStatus::PartiallyReceived,
                'arrived_at' => $delivery->arrived_at ?? now(),
            ])->save();

            $newStatus = $allReceived && $request->items->every(fn ($i) => $i->quantity_accepted >= $i->quantity_ordered - 0.0001)
                ? RequestStatus::Received
                : RequestStatus::PartiallyReceived;

            $before = $request->only(['status']);
            $request->forceFill(['status' => $newStatus])->save();

            $this->history('reception', $reception->id, 'recorded', null, [
                'delivery_id' => $delivery->id,
                'status' => $reception->status->value,
                'lines' => count($lines),
            ], $actor, null, $correlationId);
            $this->history('request', $request->id, 'status_'.$newStatus->value, $before, $request->only(['status']), $actor, null, $correlationId);

            $this->outbox->record(
                'acquisition.reception.recorded',
                'acquisition_reception',
                $reception->id,
                [
                    'reception_id' => $reception->id,
                    'delivery_id' => $delivery->id,
                    'request_id' => $request->id,
                    'reference' => $request->reference,
                    'institution_id' => $request->institution_id,
                    'status' => $reception->status->value,
                    'lines_count' => count($lines),
                    'quantity_accepted_total' => $qtyAcceptedTotal,
                    'quantity_refused_total' => $qtyRefusedTotal,
                    'received_by' => $receivedBy,
                    'received_at' => $reception->created_at->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $request->institution_id,
                actor: $actor,
            );

            return $reception->load('lines');
        });

        // Apres commit : enregistrer les biens acceptes chez Asset.
        foreach ($result->lines as $line) {
            if ((float) $line->quantity_accepted > 0) {
                RegisterAcceptedItems::dispatch($line->id);
            }
        }

        return $result;
    }

    /**
     * Cloture du dossier : tous les biens acceptes doivent etre
     * enregistres chez Asset (asset_id renseigne sur les lignes).
     *
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function closeRequest(
        AcquisitionRequest $request,
        string $closedBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): AcquisitionRequest {
        if ($request->status !== RequestStatus::Received) {
            throw new DomainException('REQUEST_NOT_RECEIVED', 'Seule une demande entierement recue peut etre cloturee.');
        }

        $pendingLines = AcquisitionReceptionLine::whereIn('item_id', $request->items->pluck('id'))
            ->where('quantity_accepted', '>', 0)
            ->whereNull('asset_ids')
            ->count();

        if ($pendingLines > 0) {
            throw new DomainException('REQUEST_ASSETS_PENDING', 'Des biens acceptes ne sont pas encore enregistres chez Asset.');
        }

        return DB::transaction(function () use ($request, $closedBy, $actor, $correlationId) {
            $before = $request->only(['status']);
            $request->forceFill([
                'status' => RequestStatus::Closed,
                'closed_by' => $closedBy,
                'closed_at' => now(),
            ])->save();

            $this->history('request', $request->id, 'closed', $before, $request->only(['status']), $actor, null, $correlationId);

            $this->outbox->record(
                'acquisition.request.closed',
                'acquisition_request',
                $request->id,
                [
                    'request_id' => $request->id,
                    'reference' => $request->reference,
                    'institution_id' => $request->institution_id,
                    'asset_ids' => $request->items->pluck('asset_id')->filter()->values()->all(),
                    'closed_at' => now()->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $request->institution_id,
                actor: $actor,
            );

            return $request->fresh('items');
        });
    }

    /**
     * Rattache les asset_ids retournes par Asset a la ligne et a
     * l'item ; tente la cloture si tout est enregistre.
     *
     * @param  array<int, string>  $assetIds
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function recordRegisteredAssets(
        AcquisitionReceptionLine $line,
        array $assetIds,
        ?array $actor = null,
        ?string $correlationId = null,
    ): void {
        DB::transaction(function () use ($line, $assetIds, $actor, $correlationId) {
            $existing = $line->asset_ids ?? [];
            $merged = array_values(array_unique([...$existing, ...$assetIds]));
            $line->forceFill([
                'asset_ids' => $merged,
                'asset_id' => $line->asset_id ?? ($merged[0] ?? null),
            ])->save();

            $this->history('reception_line', $line->id, 'assets_registered', null, [
                'asset_ids' => $merged,
            ], $actor, null, $correlationId);

            $item = $line->item;
            // L'item est rattache au premier asset une fois toutes
            // ses lignes acceptees enregistrees.
            $itemPending = AcquisitionReceptionLine::where('item_id', $item->id)
                ->where('quantity_accepted', '>', 0)
                ->whereNull('asset_ids')
                ->exists();
            if (! $itemPending && $item->asset_id === null) {
                $firstAsset = AcquisitionReceptionLine::where('item_id', $item->id)
                    ->whereNotNull('asset_ids')
                    ->orderBy('created_at')
                    ->value('asset_id');
                if ($firstAsset !== null) {
                    $item->forceFill(['asset_id' => $firstAsset])->save();
                }
            }
        });
    }

    /**
     * Annulation d'une demande non terminee.
     *
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function cancelRequest(
        AcquisitionRequest $request,
        string $motif,
        string $cancelledBy,
        ?array $actor = null,
        ?string $correlationId = null,
    ): AcquisitionRequest {
        if (! $request->status->isOpen()) {
            throw new DomainException('REQUEST_NOT_CANCELLABLE', 'Demande deja cloturee.');
        }
        if (! $request->status->canBeCancelled()) {
            throw new DomainException('REQUEST_NOT_CANCELLABLE', 'Une demande entierement recue ne peut plus etre annulee.');
        }

        return DB::transaction(function () use ($request, $motif, $cancelledBy, $actor, $correlationId) {
            $before = $request->only(['status']);
            $request->forceFill(['status' => RequestStatus::Cancelled])->save();

            $this->history('request', $request->id, 'cancelled', $before, $request->only(['status']), $actor, $motif, $correlationId);

            $this->outbox->record(
                'acquisition.request.cancelled',
                'acquisition_request',
                $request->id,
                [
                    'request_id' => $request->id,
                    'reference' => $request->reference,
                    'institution_id' => $request->institution_id,
                    'motif' => $motif,
                    'cancelled_by' => $cancelledBy,
                    'cancelled_at' => now()->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $request->institution_id,
                actor: $actor,
            );

            return $request->fresh('items');
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function nextReference(): string
    {
        $year = (int) now()->format('Y');
        $sequence = DB::table('acquisition_sequences')
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            DB::table('acquisition_sequences')->insert(['year' => $year, 'last_number' => 1]);
            $number = 1;
        } else {
            $number = $sequence->last_number + 1;
            DB::table('acquisition_sequences')->where('year', $year)->update(['last_number' => $number]);
        }

        return sprintf('ACQ-%d-%06d', $year, $number);
    }

    private function assertStatus(AcquisitionRequest $request, RequestStatus $expected, string $code): void
    {
        if ($request->status !== $expected) {
            throw new DomainException($code, "Statut attendu : {$expected->value}, actuel : {$request->status->value}.");
        }
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
        AcquisitionHistory::create([
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
