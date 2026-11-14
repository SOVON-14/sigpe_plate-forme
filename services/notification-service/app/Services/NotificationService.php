<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Exceptions\DomainException;
use App\Models\AppNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moteur de notifications SIGPE (CDC §4.12).
 *
 * - evenements metier -> notifications par destinataire resolu ;
 * - priorites ordinary/important/critical ; critical = accuse de
 *   prise en compte explicite (distinct de l'approbation) ;
 * - canaux in_app/email/sms selon preferences ; alertes obligatoires
 *   non desactivables ;
 * - statuts de livraison suivis, retries bornes, abandon apres
 *   tentatives, aucune donnee sensible dans les canaux externes.
 */
class NotificationService
{
    private const RECIPIENT_KEYS = [
        'recipient_account_ids',
        'recipient_account_id',
        'requester_account_id',
        'assignee_account_id',
        'beneficiary_account_id',
    ];

    private const MAX_DELIVERY_ATTEMPTS = 3;

    public function __construct(private readonly OutboxRecorder $outbox) {}

    /**
     * Modeles par defaut (idempotent) — le catalogue national des
     * evenements est complete par les administrateurs.
     */
    public function ensureDefaultTemplates(): void
    {
        $defaults = [
            [
                'code' => 'default',
                'event_type' => '*',
                'priority' => 'ordinary',
                'is_mandatory' => false,
                'title_template' => 'Notification SIGPE',
                'body_template' => 'Un evenement est survenu : {{event_type}}',
                'channels' => ['in_app'],
            ],
            [
                'code' => 'spare-part-threshold',
                'event_type' => 'maintenance.spare_part.below_threshold',
                'priority' => 'important',
                'is_mandatory' => false,
                'title_template' => 'Stock sous seuil : {{part_reference}}',
                'body_template' => 'La piece {{part_reference}} est sous son seuil de reapprovisionnement (stock {{quantity}}).',
                'channels' => ['in_app', 'email'],
            ],
            [
                'code' => 'workflow-completed',
                'event_type' => 'workflow.instance_completed',
                'priority' => 'important',
                'is_mandatory' => false,
                'title_template' => 'Circuit de validation termine',
                'body_template' => 'Le circuit {{instance_id}} est termine (resultat : {{outcome}}).',
                'channels' => ['in_app'],
            ],
        ];

        foreach ($defaults as $tpl) {
            NotificationTemplate::firstOrCreate(
                ['event_type' => $tpl['event_type']],
                $tpl,
            );
        }
    }

    /**
     * Transforme un evenement metier en notifications.
     * Retourne le nombre de notifications creees.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function processEvent(array $envelope): int
    {
        $this->ensureDefaultTemplates();

        $eventType = (string) ($envelope['event_type'] ?? '');
        if ($eventType === '' || str_starts_with($eventType, 'notification.')) {
            return 0;
        }

        $data = $envelope['data'] ?? [];
        $recipients = $this->resolveRecipients($data);
        if ($recipients === []) {
            return 0;
        }

        $template = NotificationTemplate::where('event_type', $eventType)
            ->where('is_active', true)
            ->first()
            ?? NotificationTemplate::where('event_type', '*')->where('is_active', true)->first();

        $priority = $template?->priority ?? NotificationPriority::Ordinary;
        $isMandatory = (bool) ($template?->is_mandatory) || $priority === NotificationPriority::Critical;

        $created = 0;
        foreach ($recipients as $recipientId) {
            // Dedoublonnage par (source_event_id, destinataire).
            $exists = AppNotification::where('source_event_id', $envelope['event_id'])
                ->where('recipient_account_id', $recipientId)
                ->exists();
            if ($exists) {
                continue;
            }

            $notification = DB::transaction(function () use ($envelope, $data, $eventType, $recipientId, $template, $priority, $isMandatory): AppNotification {
                $notification = AppNotification::create([
                    'recipient_account_id' => $recipientId,
                    'event_type' => $eventType,
                    'source_event_id' => $envelope['event_id'],
                    'template_id' => $template?->id,
                    'template_code' => $template?->code,
                    'priority' => $priority,
                    'title' => $this->render($template?->title_template ?? 'Notification SIGPE', $data, $eventType),
                    'body' => $this->render($template?->body_template ?? '', $data, $eventType),
                    'link' => is_string($data['link'] ?? null) ? $data['link'] : null,
                    'institution_id' => $envelope['institution_id'] ?? $data['institution_id'] ?? null,
                    'is_mandatory' => $isMandatory,
                ]);

                foreach ($this->channelsFor($recipientId, $template, $isMandatory) as $channel) {
                    NotificationDelivery::create([
                        'notification_id' => $notification->id,
                        'channel' => $channel,
                        'status' => DeliveryStatus::Pending,
                    ]);
                }

                return $notification;
            });

            $this->dispatchDeliveries($notification);
            $created++;
        }

        return $created;
    }

    /**
     * Confirmation de lecture — tous niveaux confondus.
     */
    public function markRead(AppNotification $notification, string $accountId): AppNotification
    {
        $this->assertOwner($notification, $accountId);

        if (! $notification->is_read) {
            $notification->update(['is_read' => true, 'read_at' => now()]);
        }

        return $notification->fresh();
    }

    /**
     * Accuse de prise en compte EXPLICITE — reserve au critique.
     * Distinct de la lecture et de toute approbation metier.
     */
    public function acknowledge(AppNotification $notification, string $accountId, ?string $comment, ?string $correlationId = null): AppNotification
    {
        $this->assertOwner($notification, $accountId);

        if ($notification->priority !== NotificationPriority::Critical) {
            throw new DomainException('NOT_CRITICAL', 'Seules les notifications critiques exigent un accuse de prise en compte.');
        }
        if ($notification->acknowledged_at !== null) {
            throw new DomainException('ALREADY_ACKNOWLEDGED', 'Notification deja accusee.');
        }

        $notification->update([
            'is_read' => true,
            'read_at' => $notification->read_at ?? now(),
            'acknowledged_at' => now(),
            'acknowledged_by' => $accountId,
            'acknowledgment_comment' => $comment,
        ]);

        $this->outbox->record(
            'notification.acknowledged',
            'notification',
            $notification->id,
            [
                'notification_id' => $notification->id,
                'recipient_account_id' => $notification->recipient_account_id,
                'event_type' => $notification->event_type,
                'acknowledged_by' => $accountId,
                'comment' => $comment,
                'acknowledged_at' => now()->toIso8601String(),
            ],
            correlationId: $correlationId,
            institutionId: $notification->institution_id,
            actor: ['type' => 'user', 'id' => $accountId],
        );

        return $notification->fresh();
    }

    /**
     * Relance les livraisons en echec ; abandon apres
     * MAX_DELIVERY_ATTEMPTS tentatives.
     *
     * @return array{retried: int, abandoned: int}
     */
    public function retryFailedDeliveries(): array
    {
        $retried = 0;
        $abandoned = 0;

        NotificationDelivery::where('status', DeliveryStatus::Failed)->each(function (NotificationDelivery $delivery) use (&$retried, &$abandoned): void {
            if ($delivery->attempts >= self::MAX_DELIVERY_ATTEMPTS) {
                $delivery->update(['status' => DeliveryStatus::Abandoned]);
                $abandoned++;
                $this->outbox->record(
                    'notification.delivery_failed',
                    'notification_delivery',
                    $delivery->id,
                    [
                        'delivery_id' => $delivery->id,
                        'notification_id' => $delivery->notification_id,
                        'recipient_account_id' => $delivery->notification?->recipient_account_id,
                        'channel' => $delivery->channel->value,
                        'attempts' => $delivery->attempts,
                        'last_error' => $delivery->last_error,
                        'failed_at' => now()->toIso8601String(),
                    ],
                    institutionId: $delivery->notification?->institution_id,
                    actor: ['type' => 'system', 'id' => (string) Str::uuid7()],
                );

                return;
            }

            $this->send($delivery->fresh());
            $retried++;
        });

        return ['retried' => $retried, 'abandoned' => $abandoned];
    }

    /**
     * @return list<NotificationPreference>
     */
    public function preferencesFor(string $accountId): array
    {
        return NotificationPreference::where('account_id', $accountId)->get()->all();
    }

    /**
     * Definit les preferences ; les evenements a modele obligatoire
     * ou critique sont forces (non desactivables).
     *
     * @param  array<int, array{event_type: string, channel: string, enabled: bool}>  $items
     * @return list<NotificationPreference>
     */
    public function updatePreferences(string $accountId, array $items): array
    {
        $this->ensureDefaultTemplates();

        foreach ($items as $item) {
            $template = NotificationTemplate::where('event_type', $item['event_type'])->first();
            $forced = $template !== null
                && ($template->is_mandatory || $template->priority === NotificationPriority::Critical);

            NotificationPreference::updateOrCreate(
                [
                    'account_id' => $accountId,
                    'event_type' => $item['event_type'],
                    'channel' => $item['channel'],
                ],
                [
                    'enabled' => $forced ? true : $item['enabled'],
                    'forced' => $forced,
                ],
            );
        }

        return $this->preferencesFor($accountId);
    }

    /**
     * @return list<string>
     */
    private function resolveRecipients(array $data): array
    {
        $ids = [];
        foreach (self::RECIPIENT_KEYS as $key) {
            $value = $data[$key] ?? null;
            if (is_array($value)) {
                $ids = array_merge($ids, array_filter($value, 'is_string'));
            } elseif (is_string($value)) {
                $ids[] = $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Canaux effectifs : template ∩ preferences du destinataire.
     * Les notifications obligatoires ignorent les desactivations.
     *
     * @return list<NotificationChannel>
     */
    private function channelsFor(string $accountId, ?NotificationTemplate $template, bool $isMandatory): array
    {
        $templateChannels = $template?->channels ?? [NotificationChannel::InApp->value];
        $channels = [];

        foreach ($templateChannels as $channelValue) {
            $channel = NotificationChannel::tryFrom((string) $channelValue) ?? NotificationChannel::InApp;
            if ($isMandatory || $this->channelEnabled($accountId, $template?->event_type ?? '*', $channel)) {
                $channels[] = $channel;
            }
        }

        // in_app est toujours disponible.
        if (! in_array(NotificationChannel::InApp, $channels, true) && $isMandatory) {
            $channels[] = NotificationChannel::InApp;
        }

        return array_values(array_unique($channels, SORT_REGULAR));
    }

    private function channelEnabled(string $accountId, string $eventType, NotificationChannel $channel): bool
    {
        $preference = NotificationPreference::where('account_id', $accountId)
            ->where('channel', $channel->value)
            ->whereIn('event_type', [$eventType, '*'])
            ->orderByRaw('CASE WHEN event_type = ? THEN 0 ELSE 1 END', [$eventType])
            ->first();

        // In_app ne peut pas etre desactive ; email/sms le peuvent.
        if ($channel === NotificationChannel::InApp) {
            return $preference === null || $preference->enabled;
        }

        return $preference === null || $preference->enabled;
    }

    /**
     * Envoi simule par canal : in_app delivre immediatement ;
     * email/sms marquent 'sent' (transport externe a brancher).
     * Aucune donnee sensible n'est placee dans le message externe.
     */
    private function dispatchDeliveries(AppNotification $notification): void
    {
        $notification->deliveries()->where('status', DeliveryStatus::Pending)->each(function (NotificationDelivery $delivery): void {
            $this->send($delivery);
        });

        $this->outbox->record(
            'notification.created',
            'notification',
            $notification->id,
            [
                'notification_id' => $notification->id,
                'recipient_account_id' => $notification->recipient_account_id,
                'event_type' => $notification->event_type,
                'source_event_id' => $notification->source_event_id,
                'priority' => $notification->priority->value,
                'is_mandatory' => $notification->is_mandatory,
                'channels' => $notification->deliveries->pluck('channel')->map->value->all(),
                'institution_id' => $notification->institution_id,
                'created_at' => $notification->created_at->toIso8601String(),
            ],
            institutionId: $notification->institution_id,
            actor: ['type' => 'service', 'id' => (string) Str::uuid7()],
        );
    }

    private function send(NotificationDelivery $delivery): void
    {
        $delivery->increment('attempts');

        // Transport simule : in_app = delivered ; email/sms = sent.
        // Un echec peut etre injecte en test via config.
        $failed = config('notification.force_failure') === true
            && $delivery->channel !== NotificationChannel::InApp;

        if ($failed) {
            $delivery->update([
                'status' => DeliveryStatus::Failed,
                'last_error' => 'Transport indisponible (simulation)',
            ]);

            return;
        }

        $delivery->update([
            'status' => $delivery->channel === NotificationChannel::InApp
                ? DeliveryStatus::Delivered
                : DeliveryStatus::Sent,
            'sent_at' => now(),
            'delivered_at' => $delivery->channel === NotificationChannel::InApp ? now() : null,
            'last_error' => null,
        ]);
    }

    private function render(string $template, array $data, string $eventType): string
    {
        $flat = array_merge(['event_type' => $eventType], $this->flatten($data));
        $result = $template;
        foreach ($flat as $key => $value) {
            if (is_scalar($value)) {
                $result = str_replace('{{'.$key.'}}', (string) $value, $result);
            }
        }

        return $result;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $out += $this->flatten($value, $name);
            } elseif (is_scalar($value) || $value === null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    private function assertOwner(AppNotification $notification, string $accountId): void
    {
        if ($notification->recipient_account_id !== $accountId) {
            throw new DomainException('FORBIDDEN', 'Notification d\'un autre compte.');
        }
    }
}
