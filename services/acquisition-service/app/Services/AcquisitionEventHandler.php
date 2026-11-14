<?php

namespace App\Services;

use App\Models\AcquisitionRequest;
use Illuminate\Support\Facades\Log;

/**
 * Consommateur des evenements entrants (contracts/asyncapi).
 *
 * - workflow.instance_completed : outcome du circuit de validation
 *   d'une demande d'acquisition (operation_type=acquisition).
 */
class AcquisitionEventHandler
{
    public function __construct(
        private readonly AcquisitionService $acquisitions,
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
        // On ne consomme que les instances de domaine acquisition.
        $operationType = $data['operation_type'] ?? null;
        if ($operationType !== null && $operationType !== 'acquisition') {
            return 'ignored';
        }

        $instanceId = $data['instance_id'] ?? null;
        $operationRef = $data['operation_ref'] ?? null;

        $request = null;
        if ($instanceId !== null) {
            $request = AcquisitionRequest::where('workflow_instance_id', $instanceId)->first();
        }
        if ($request === null && $operationRef !== null) {
            $request = AcquisitionRequest::find($operationRef);
        }
        if ($request === null) {
            Log::info('acquisition.event.unknown_request', [
                'instance_id' => $instanceId,
                'operation_ref' => $operationRef,
            ]);

            return 'ignored';
        }

        $this->acquisitions->handleWorkflowOutcome(
            (string) ($instanceId ?? $request->workflow_instance_id),
            (string) ($data['outcome'] ?? ''),
            $data['decided_by'] ?? null,
            ['type' => 'service', 'id' => 'workflow-service'],
            $envelope['correlation_id'] ?? null,
            $data['decision_comment'] ?? null,
        );

        return 'processed';
    }
}
