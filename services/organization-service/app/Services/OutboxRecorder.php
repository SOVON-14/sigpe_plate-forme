<?php

namespace App\Services;

use App\Models\OutboxMessage;
use Illuminate\Support\Str;

class OutboxRecorder
{
    public const PRODUCER = 'organization-service';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{type?: string, id?: string}|null  $actor
     */
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?string $institutionId = null,
        ?array $actor = null,
    ): OutboxMessage {
        return OutboxMessage::create([
            'event_type' => $eventType,
            'event_version' => 'v1',
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
            'correlation_id' => $correlationId,
            'causation_id' => $causationId ?? $correlationId,
            'institution_id' => $institutionId,
            'actor' => $actor ?? ['type' => 'system', 'id' => self::PRODUCER],
            'occurred_at' => now(),
            'status' => 'pending',
        ]);
    }
}
