<?php

namespace App\Services;

/**
 * Consommateur de TOUS les evenements metier (binding topic '#').
 * Chaque evenement devient une entree de journal immuable ;
 * l'inbox garantit le dedoublonnage at-least-once.
 */
class AuditEventHandler
{
    public function __construct(
        private readonly AuditService $audit,
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
            $entry = $this->audit->recordFromEnvelope($envelope);
            $this->inbox->markProcessed((string) $eventId);

            return $entry->wasRecentlyCreated ? 'recorded' : 'duplicate';
        } catch (\Throwable $e) {
            $this->inbox->markFailed((string) $eventId, $e);
            throw $e;
        }
    }
}
