<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Models\MovementOperation;
use Illuminate\Support\Facades\Log;

/**
 * Consommateur des evenements entrants (contracts/asyncapi).
 *
 * - workflow.instance_completed : outcome du circuit de validation
 *   (approved / rejected / correction_requested / cancelled).
 * - asset.rattachement_confirmed : TEC-017 — confirmation Asset du
 *   changement de rattachement et du nouveau code patrimonial.
 */
class MovementEventHandler
{
    public function __construct(
        private readonly MovementService $movements,
        private readonly EventInbox $inbox,
    ) {}

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function handle(array $envelope): string
    {
        $eventType = $envelope['event_type'] ?? '';

        if (! $this->inbox->begin($envelope)) {
            return 'duplicate';
        }

        try {
            $result = match ($eventType) {
                'workflow.instance_completed' => $this->onWorkflowCompleted($envelope['data'] ?? [], $envelope),
                'asset.rattachement_confirmed' => $this->onAssetRattachementConfirmed($envelope['data'] ?? [], $envelope),
                default => 'ignored',
            };

            $this->inbox->markProcessed((string) $envelope['event_id']);

            return $result;
        } catch (\Throwable $e) {
            $this->inbox->markFailed((string) $envelope['event_id'], $e);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function onWorkflowCompleted(array $data, array $envelope): string
    {
        $operation = $this->findOperationForWorkflow($data);
        if ($operation === null) {
            Log::info('movement.event.unknown_operation', [
                'instance_id' => $data['instance_id'] ?? null,
                'operation_ref' => $data['operation_ref'] ?? null,
            ]);

            return 'ignored';
        }

        $this->movements->handleWorkflowOutcome(
            (string) ($data['instance_id'] ?? $operation->workflow_instance_id),
            (string) ($data['outcome'] ?? ''),
            $data['decided_by'] ?? null,
            ['type' => 'service', 'id' => 'workflow-service'],
            $envelope['correlation_id'] ?? null,
            $data['decision_comment'] ?? null,
        );

        return 'processed';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $envelope
     */
    private function onAssetRattachementConfirmed(array $data, array $envelope): string
    {
        $assetId = $data['asset_id'] ?? null;
        $newInstitutionId = $data['institution_id'] ?? null;
        $newCode = $data['patrimonial_code'] ?? null;

        if ($assetId === null || $newInstitutionId === null || $newCode === null) {
            return 'ignored';
        }

        // Operations en attente de confirmation Asset pour ce bien
        // et cette institution de destination (TEC-017).
        $operations = MovementOperation::where('status', OperationStatus::PendingAssetConfirmation)
            ->where('destination_institution_id', $newInstitutionId)
            ->whereHas('assets', fn ($q) => $q->where('asset_id', $assetId))
            ->get();

        foreach ($operations as $operation) {
            $this->movements->recordAssetRattachementConfirmation(
                $operation,
                [$assetId => $newCode],
                ['type' => 'service', 'id' => 'asset-service'],
                $envelope['correlation_id'] ?? null,
            );
        }

        return 'processed';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findOperationForWorkflow(array $data): ?MovementOperation
    {
        if (! empty($data['instance_id'])) {
            $operation = MovementOperation::where('workflow_instance_id', $data['instance_id'])->first();
            if ($operation !== null) {
                return $operation;
            }
        }

        // Repli : operation_ref peut designer l'operation Movement.
        if (! empty($data['operation_ref'])) {
            return MovementOperation::find($data['operation_ref']);
        }

        return null;
    }
}
