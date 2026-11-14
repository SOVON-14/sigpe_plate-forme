<?php

namespace App\Services;

use App\Models\InboxMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Inbox pattern : dedoublonnage des evenements consommes.
 * L'unicite sur event_id garantit qu'un meme evenement n'est
 * traite qu'une seule fois meme en cas de rejeu (at-least-once).
 */
class EventInbox
{
    /**
     * @param  array<string, mixed>  $envelope
     * @return bool true si l'evenement doit etre traite, false si doublon
     */
    public function begin(array $envelope): bool
    {
        $eventId = $envelope['event_id'] ?? null;
        if ($eventId === null) {
            return true;
        }

        try {
            InboxMessage::create([
                'event_id' => $eventId,
                'event_type' => $envelope['event_type'] ?? 'unknown',
                'producer' => $envelope['producer'] ?? null,
                'aggregate_id' => $envelope['aggregate_id'] ?? null,
                'payload' => $envelope,
                'correlation_id' => $envelope['correlation_id'] ?? null,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            $existing = InboxMessage::where('event_id', $eventId)->first();

            return $existing !== null && $existing->processed_at === null;
        }
    }

    public function markProcessed(string $eventId): void
    {
        InboxMessage::where('event_id', $eventId)->update(['processed_at' => now()]);
    }

    public function markFailed(string $eventId, \Throwable $e): void
    {
        InboxMessage::where('event_id', $eventId)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
        ]);
    }
}
