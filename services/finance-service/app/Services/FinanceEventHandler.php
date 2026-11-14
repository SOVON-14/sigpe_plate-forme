<?php

namespace App\Services;

/**
 * Consommateur des evenements metier pour Finance Service :
 * - asset.registered.v1      -> valorisation initiale indeterminee ;
 * - maintenance.intervention.completed.v1 -> lignes de couts ;
 * - workflow.instance_completed.v1       -> issue des dossiers de sortie.
 */
class FinanceEventHandler
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly EventInbox $inbox,
    ) {}

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function handle(array $envelope): string
    {
        $eventId = $envelope['event_id'] ?? null;
        if ($eventId === null) {
            return 'ignored';
        }

        if (! $this->inbox->begin($envelope)) {
            return 'duplicate';
        }

        try {
            $result = match ($envelope['event_type'] ?? '') {
                'asset.registered.v1' => $this->onAssetRegistered($envelope),
                'maintenance.intervention.completed.v1' => $this->onInterventionCompleted($envelope),
                'workflow.instance_completed.v1' => $this->onWorkflowCompleted($envelope),
                default => 'ignored',
            };

            $this->inbox->markProcessed((string) $eventId);

            return $result;
        } catch (\Throwable $e) {
            $this->inbox->markFailed((string) $eventId, $e);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function onAssetRegistered(array $envelope): string
    {
        $data = $envelope['data'] ?? [];
        $this->finance->handleAssetRegistered(
            is_array($data) ? $data : [],
            $envelope['institution_id'] ?? null,
            $envelope['correlation_id'] ?? null,
        );

        return 'processed';
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function onInterventionCompleted(array $envelope): string
    {
        $data = $envelope['data'] ?? [];
        $count = $this->finance->recordInterventionCosts(
            is_array($data) ? $data : [],
            $envelope['institution_id'] ?? null,
            $envelope['correlation_id'] ?? null,
        );

        return "costs:{$count}";
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function onWorkflowCompleted(array $envelope): string
    {
        $data = $envelope['data'] ?? [];
        $instanceId = $data['instance_id'] ?? $data['workflow_instance_id'] ?? null;
        $outcome = $data['outcome'] ?? null;

        if (! is_string($instanceId) || ! is_string($outcome)) {
            return 'ignored';
        }

        $this->finance->handleWorkflowOutcome(
            $instanceId,
            $outcome,
            (string) ($data['decided_by'] ?? $data['actor_id'] ?? $envelope['actor']['id'] ?? 'system'),
        );

        return 'processed';
    }
}
