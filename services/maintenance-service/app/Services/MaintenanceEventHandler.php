<?php

namespace App\Services;

use App\Models\MaintenanceRequest;
use Illuminate\Support\Facades\Log;

/**
 * Consommateur des evenements entrants (contracts/asyncapi).
 *
 * - workflow.instance_completed : outcome du circuit de validation
 *   d'une demande de maintenance (operation_type=maintenance).
 */
class MaintenanceEventHandler
{
    public function __construct(
        private readonly MaintenanceService $maintenance,
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
     * @param  array<string, mixed>  $envelope
     */
    private function onWorkflowCompleted(array $data, array $envelope): string
    {
        // On ne consomme que les instances de domaine maintenance.
        $operationType = $data['operation_type'] ?? null;
        if ($operationType !== null && $operationType !== 'maintenance') {
            return 'ignored';
        }

        $instanceId = $data['instance_id'] ?? null;
        $operationRef = $data['operation_ref'] ?? null;

        $request = null;
        if ($instanceId !== null) {
            $request = MaintenanceRequest::where('workflow_instance_id', $instanceId)->first();
        }
        if ($request === null && $operationRef !== null) {
            $request = MaintenanceRequest::find($operationRef);
        }
        if ($request === null) {
            Log::info('maintenance.event.unknown_request', [
                'instance_id' => $instanceId,
                'operation_ref' => $operationRef,
            ]);

            return 'ignored';
        }

        $this->maintenance->handleWorkflowOutcome(
            (string) ($instanceId ?? $request->workflow_instance_id),
            (string) ($data['outcome'] ?? ''),
            $data['decided_by'] ?? null,
            $data['decision_comment'] ?? null,
            ['type' => 'service', 'id' => 'workflow-service'],
            $envelope['correlation_id'] ?? null,
        );

        return 'processed';
    }
}
