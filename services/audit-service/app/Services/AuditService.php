<?php

namespace App\Services;

use App\Models\AuditEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Coeur du journal d'audit : enregistrement immuable des evenements
 * metier avec chaine de hachage (catalogue v1.1 §8.4, CDC §4.13).
 *
 * Chaque entree_hash = SHA-256(previous_hash | champs canoniques).
 * Toute alteration, insertion ou suppression rompt la chaine et est
 * detectee par verifyChain().
 */
class AuditService
{
    /** Producteurs Evenements -> criticite par defaut du domaine. */
    private const SENSITIVE_DOMAINS = [
        'identity', 'movement', 'workflow', 'finance', 'document',
        'acquisition', 'asset', 'inventory', 'maintenance',
    ];

    private const CRITICAL_PREFIXES = [
        'identity.', 'movement.', 'workflow.instance_completed',
        'asset.rattachement', 'asset.institutional_attachment',
    ];

    public function __construct(private readonly OutboxRecorder $outbox) {}

    /**
     * Transforme une enveloppe evenementielle en entree de journal
     * immuable. Retourne l'entree creee ou existante (dedoublonnage
     * event_id — meme garantie que l'inbox).
     *
     * @param  array<string, mixed>  $envelope
     */
    public function recordFromEnvelope(array $envelope): AuditEntry
    {
        $existing = AuditEntry::where('event_id', $envelope['event_id'])->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($envelope): AuditEntry {
            $data = $envelope['data'] ?? [];
            $eventType = (string) ($envelope['event_type'] ?? 'unknown');

            $attributes = [
                'event_id' => $envelope['event_id'],
                'producer' => (string) ($envelope['producer'] ?? 'unknown'),
                'event_type' => $eventType,
                'event_version' => (int) ($envelope['event_version'] ?? 1),
                'occurred_at' => $this->normalizeDateTime($envelope['occurred_at'] ?? now()),
                'actor_id' => $envelope['actor']['id'] ?? $data['actor_id'] ?? $data['account_id'] ?? null,
                'actor_type' => $envelope['actor']['type'] ?? null,
                'institution_id' => $envelope['institution_id'] ?? $data['institution_id'] ?? null,
                'operation' => $data['operation'] ?? $eventType,
                'resource_type' => $data['resource_type'] ?? $data['aggregate_type'] ?? null,
                'resource_id' => $data['resource_id'] ?? $envelope['aggregate_id'] ?? null,
                'old_value' => $data['old_value'] ?? $data['previous_value'] ?? null,
                'new_value' => $data['new_value'] ?? null,
                'reason' => $data['reason'] ?? $data['motif'] ?? $data['justification'] ?? null,
                'result' => $this->resolveResult($data, $eventType),
                'correlation_id' => $envelope['correlation_id'] ?? null,
                'evidence_hash' => $data['evidence_hash'] ?? $data['document_hash'] ?? null,
                'sensitivity' => $this->classifySensitivity($eventType, $envelope['producer'] ?? ''),
                'payload' => $envelope,
            ];

            $previousHash = AuditEntry::orderByDesc('seq')->value('entry_hash');
            $attributes['previous_hash'] = $previousHash;
            $attributes['entry_hash'] = $this->computeHash($attributes, $previousHash);

            $entry = AuditEntry::create($attributes);

            if ($entry->sensitivity !== 'normal') {
                $this->outbox->record(
                    'audit.entry_recorded',
                    'audit_entry',
                    $entry->id,
                    [
                        'entry_id' => $entry->id,
                        'event_id' => $entry->event_id,
                        'producer' => $entry->producer,
                        'event_type' => $entry->event_type,
                        'institution_id' => $entry->institution_id,
                        'actor_id' => $entry->actor_id,
                        'operation' => $entry->operation,
                        'result' => $entry->result,
                        'sensitivity' => $entry->sensitivity,
                        'occurred_at' => $entry->occurred_at->toIso8601String(),
                    ],
                    correlationId: $entry->correlation_id,
                    institutionId: $entry->institution_id,
                    actor: $entry->actor_id !== null
                        ? ['type' => $entry->actor_type ?? 'user', 'id' => $entry->actor_id]
                        : ['type' => 'service', 'id' => (string) Str::uuid7()],
                );
            }

            return $entry;
        });
    }

    /**
     * Recalcule la chaine de hachage complete. Retourne le premier
     * maillon rompu le cas echeant.
     *
     * @return array{valid: bool, entries_checked: int, first_invalid_sequence: ?int, first_invalid_entry_id: ?string}
     */
    public function verifyChain(): array
    {
        $entries = AuditEntry::orderBy('seq')->get();
        $expectedPrevious = null;
        $checked = 0;
        $firstInvalid = null;

        foreach ($entries as $entry) {
            $checked++;
            $attributes = $entry->getAttributes();
            $recomputed = $this->computeHashFromRow($attributes);
            $linkOk = $entry->previous_hash === $expectedPrevious;
            $hashOk = hash_equals((string) $entry->entry_hash, $recomputed);

            if (! $linkOk || ! $hashOk) {
                $firstInvalid = $entry;
                break;
            }

            $expectedPrevious = $entry->entry_hash;
        }

        return [
            'valid' => $firstInvalid === null,
            'entries_checked' => $checked,
            'first_invalid_sequence' => $firstInvalid?->seq,
            'first_invalid_entry_id' => $firstInvalid?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(?string $from = null, ?string $to = null, ?string $institutionId = null): array
    {
        $query = AuditEntry::query();
        if ($institutionId !== null) {
            $query->where('institution_id', $institutionId);
        }
        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('occurred_at', '<=', $to);
        }

        $clone = clone $query;
        $byResult = (clone $clone)->selectRaw('result, COUNT(*) as c')->groupBy('result')->pluck('c', 'result')->all();
        $byProducer = (clone $clone)->selectRaw('producer, COUNT(*) as c')->groupBy('producer')->pluck('c', 'producer')->all();
        $byOperation = (clone $clone)->selectRaw('operation, COUNT(*) as c')->groupBy('operation')->orderByDesc('c')->limit(50)->pluck('c', 'operation')->all();

        $dayExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', occurred_at)"
            : "TO_CHAR(occurred_at, 'YYYY-MM-DD')";

        $daily = (clone $clone)
            ->selectRaw("{$dayExpr} as day, COUNT(*) as c")
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('c', 'day')
            ->map(fn ($c, $day) => ['date' => $day, 'count' => (int) $c])
            ->values()
            ->all();

        return [
            'total_entries' => (clone $clone)->count(),
            'by_result' => $byResult,
            'by_producer' => $byProducer,
            'by_operation' => $byOperation,
            'daily' => $daily,
            'first_entry_at' => (clone $clone)->min('occurred_at'),
            'last_entry_at' => (clone $clone)->max('occurred_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveResult(array $data, string $eventType): string
    {
        $result = $data['result'] ?? $data['outcome'] ?? null;
        if (is_string($result)) {
            return match (strtolower($result)) {
                'approved', 'validated', 'completed', 'success', 'ok' => 'success',
                'rejected', 'denied', 'cancelled', 'failed', 'error' => 'denied',
                default => 'success',
            };
        }

        return str_contains($eventType, 'rejected') || str_contains($eventType, 'cancelled') ? 'denied' : 'success';
    }

    private function classifySensitivity(string $eventType, string $producer): string
    {
        $domain = explode('.', $eventType)[0] ?? '';
        foreach (self::CRITICAL_PREFIXES as $prefix) {
            if (str_starts_with($eventType, $prefix)) {
                return 'critical';
            }
        }

        if (in_array($domain, self::SENSITIVE_DOMAINS, true) || in_array($producer, [
            'identity-service', 'movement-service', 'workflow-service',
            'finance-service', 'document-service', 'acquisition-service',
        ], true)) {
            return 'sensitive';
        }

        return 'normal';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function computeHash(array $attributes, ?string $previousHash): string
    {
        return hash('sha256', ($previousHash ?? 'GENESIS').'|'.$this->canonicalContent($attributes));
    }

    /**
     * @param  array<string, mixed>  $row  attributs bruts (getAttributes)
     */
    private function computeHashFromRow(array $row): string
    {
        $attributes = [
            'event_id' => $row['event_id'],
            'producer' => $row['producer'],
            'event_type' => $row['event_type'],
            'event_version' => (int) $row['event_version'],
            'occurred_at' => $this->normalizeDateTime($row['occurred_at']),
            'actor_id' => $row['actor_id'],
            'actor_type' => $row['actor_type'],
            'institution_id' => $row['institution_id'],
            'operation' => $row['operation'],
            'resource_type' => $row['resource_type'],
            'resource_id' => $row['resource_id'],
            'old_value' => $this->decodeJsonColumn($row['old_value'] ?? null),
            'new_value' => $this->decodeJsonColumn($row['new_value'] ?? null),
            'reason' => $row['reason'],
            'result' => $row['result'],
            'correlation_id' => $row['correlation_id'],
            'evidence_hash' => $row['evidence_hash'],
            'sensitivity' => $row['sensitivity'],
            'payload' => $this->decodeJsonColumn($row['payload'] ?? null),
        ];

        return hash('sha256', ($row['previous_hash'] ?? 'GENESIS').'|'.$this->canonicalContent($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function canonicalContent(array $attributes): string
    {
        $ordered = [];
        foreach ([
            'event_id', 'producer', 'event_type', 'event_version', 'occurred_at',
            'actor_id', 'actor_type', 'institution_id', 'operation',
            'resource_type', 'resource_id', 'old_value', 'new_value',
            'reason', 'result', 'correlation_id', 'evidence_hash',
            'sensitivity', 'payload',
        ] as $key) {
            $ordered[$key] = $attributes[$key] ?? null;
        }

        return json_encode($this->sortRecursive($ordered), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortRecursive($v), $value);
        }
        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = $this->sortRecursive($v);
        }

        return $value;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function decodeJsonColumn(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return $decoded ?? $value;
        }

        return $value;
    }
}
