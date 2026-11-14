<?php

namespace App\Services;

/**
 * Consommateur des evenements metier (binding '#'). Les evenements
 * notification.* sont ignores par le service (pas d'auto-alimentation).
 */
class NotificationEventHandler
{
    public function __construct(
        private readonly NotificationService $notifications,
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
            $count = $this->notifications->processEvent($envelope);
            $this->inbox->markProcessed((string) $eventId);

            return $count > 0 ? "created:{$count}" : 'ignored';
        } catch (\Throwable $e) {
            $this->inbox->markFailed((string) $eventId, $e);
            throw $e;
        }
    }
}
