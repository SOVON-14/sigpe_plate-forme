<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Models\AppNotification;
use App\Models\InboxMessage;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\OutboxMessage;
use App\Services\NotificationEventHandler;
use App\Services\NotificationService;
use App\Services\TokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private string $sub;

    private string $institutionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sub = (string) Str::uuid7();
        $this->institutionId = (string) Str::uuid7();
    }

    private function auth(array $claims = []): array
    {
        $merged = array_merge([
            'sub' => $this->sub,
            'permissions' => ['notification.read', 'notification.manage'],
            'institution_id' => $this->institutionId,
        ], $claims);

        $this->app->instance(TokenValidator::class, new class($merged) implements TokenValidator
        {
            public function __construct(private array $claims) {}

            public function validate(string $token): array
            {
                return $this->claims;
            }
        });

        return ['Authorization' => 'Bearer fake-token'];
    }

    private function envelope(string $eventType, array $data, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? (string) Str::uuid7(),
            'event_type' => $eventType,
            'event_version' => 1,
            'producer' => 'maintenance-service',
            'aggregate_type' => 'spare_part',
            'aggregate_id' => (string) Str::uuid7(),
            'institution_id' => $this->institutionId,
            'occurred_at' => now()->toIso8601String(),
            'correlation_id' => (string) Str::uuid7(),
            'data' => $data,
        ];
    }

    // ─── Evenements → notifications ────────────────────────────────────

    public function test_event_cree_une_notification_par_destinataire_avec_livraisons(): void
    {
        $recipient = (string) Str::uuid7();
        $eventId = (string) Str::uuid7();

        $count = $this->app->make(NotificationEventHandler::class)->handle(
            $this->envelope('maintenance.spare_part.below_threshold', [
                'part_reference' => 'PR-001',
                'quantity' => 2,
                'recipient_account_ids' => [$recipient],
            ], $eventId)
        );

        $this->assertSame('created:1', $count);

        $notification = AppNotification::where('recipient_account_id', $recipient)->sole();
        $this->assertSame('maintenance.spare_part.below_threshold', $notification->event_type);
        $this->assertSame(NotificationPriority::Important, $notification->priority);
        $this->assertStringContainsString('PR-001', $notification->title);
        $this->assertSame($this->institutionId, $notification->institution_id);

        $channels = $notification->deliveries->pluck('channel')->all();
        $this->assertEqualsCanonicalizing([NotificationChannel::InApp, NotificationChannel::Email], $channels);

        // in_app delivre, email envoye
        $byChannel = $notification->deliveries->keyBy(fn ($d) => $d->channel->value);
        $this->assertSame(DeliveryStatus::Delivered, $byChannel['in_app']->status);
        $this->assertSame(DeliveryStatus::Sent, $byChannel['email']->status);

        // notification.created dans l'outbox
        $this->assertTrue(OutboxMessage::where('event_type', 'notification.created')
            ->where('aggregate_id', $notification->id)->exists());
    }

    public function test_evenement_duplique_est_ignore(): void
    {
        $recipient = (string) Str::uuid7();
        $eventId = (string) Str::uuid7();
        $handler = $this->app->make(NotificationEventHandler::class);
        $envelope = $this->envelope('workflow.instance_completed', [
            'instance_id' => (string) Str::uuid7(),
            'outcome' => 'approved',
            'recipient_account_id' => $recipient,
        ], $eventId);

        $this->assertSame('created:1', $handler->handle($envelope));
        $this->assertSame('duplicate', $handler->handle($envelope));
        $this->assertSame(1, AppNotification::count());
        $this->assertSame(1, InboxMessage::where('event_id', $eventId)->count());
    }

    public function test_evenements_notification_sont_ignores(): void
    {
        $count = $this->app->make(NotificationEventHandler::class)->handle(
            $this->envelope('notification.created', ['recipient_account_ids' => [(string) Str::uuid7()]])
        );

        $this->assertSame('ignored', $count);
        $this->assertSame(0, AppNotification::count());
    }

    public function test_modele_wildcard_et_rendu_des_variables(): void
    {
        $recipient = (string) Str::uuid7();
        $this->app->make(NotificationEventHandler::class)->handle(
            $this->envelope('asset.created', [
                'asset_reference' => 'AST-42',
                'recipient_account_id' => $recipient,
            ])
        );

        $notification = AppNotification::where('recipient_account_id', $recipient)->sole();
        $this->assertSame('Notification SIGPE', $notification->title);
        $this->assertStringContainsString('asset.created', $notification->body);
        $this->assertSame(NotificationPriority::Ordinary, $notification->priority);
        // wildcard = in_app uniquement
        $this->assertCount(1, $notification->deliveries);
    }

    // ─── Preferences ───────────────────────────────────────────────────

    public function test_preference_desactive_le_canal_email(): void
    {
        $recipient = (string) Str::uuid7();
        NotificationPreference::create([
            'account_id' => $recipient,
            'event_type' => 'maintenance.spare_part.below_threshold',
            'channel' => 'email',
            'enabled' => false,
        ]);

        $this->app->make(NotificationEventHandler::class)->handle(
            $this->envelope('maintenance.spare_part.below_threshold', [
                'part_reference' => 'PR-009',
                'quantity' => 1,
                'recipient_account_ids' => [$recipient],
            ])
        );

        $notification = AppNotification::where('recipient_account_id', $recipient)->sole();
        $this->assertSame([NotificationChannel::InApp], $notification->deliveries->pluck('channel')->all());
    }

    public function test_modele_obligatoire_ignore_les_preferences(): void
    {
        $recipient = (string) Str::uuid7();
        $this->app->make(NotificationService::class)->ensureDefaultTemplates();
        NotificationTemplate::where('event_type', 'maintenance.spare_part.below_threshold')
            ->update(['is_mandatory' => true]);
        NotificationPreference::create([
            'account_id' => $recipient,
            'event_type' => 'maintenance.spare_part.below_threshold',
            'channel' => 'email',
            'enabled' => false,
        ]);

        $this->app->make(NotificationEventHandler::class)->handle(
            $this->envelope('maintenance.spare_part.below_threshold', [
                'part_reference' => 'PR-010',
                'quantity' => 0,
                'recipient_account_ids' => [$recipient],
            ])
        );

        $notification = AppNotification::where('recipient_account_id', $recipient)->sole();
        $this->assertTrue($notification->is_mandatory);
        // email envoye malgre la preference desactivee
        $this->assertCount(2, $notification->deliveries);
    }

    public function test_update_preferences_force_les_evenements_obligatoires(): void
    {
        $this->app->make(NotificationService::class)->ensureDefaultTemplates();
        NotificationTemplate::where('event_type', 'maintenance.spare_part.below_threshold')
            ->update(['is_mandatory' => true]);

        $headers = $this->auth();
        $response = $this->putJson('/api/v1/notification-preferences', [
            'preferences' => [[
                'event_type' => 'maintenance.spare_part.below_threshold',
                'channel' => 'email',
                'enabled' => false,
            ]],
        ], $headers);

        $response->assertOk();
        $pref = NotificationPreference::where('account_id', $this->sub)->sole();
        $this->assertTrue($pref->enabled);
        $this->assertTrue($pref->forced);
    }

    // ─── Lecture / accuse / API ─────────────────────────────────────────

    private function criticalNotification(string $recipient): AppNotification
    {
        return AppNotification::create([
            'recipient_account_id' => $recipient,
            'event_type' => 'movement.incident_reported',
            'source_event_id' => (string) Str::uuid7(),
            'priority' => NotificationPriority::Critical,
            'title' => 'Incident signale',
            'body' => 'Perte signalee',
            'is_mandatory' => true,
        ]);
    }

    public function test_liste_et_non_lus_pour_le_proprietaire(): void
    {
        $other = (string) Str::uuid7();
        AppNotification::create([
            'recipient_account_id' => $this->sub,
            'event_type' => 'x.y',
            'priority' => 'ordinary',
            'title' => 'T', 'body' => 'B',
        ]);
        AppNotification::create([
            'recipient_account_id' => $other,
            'event_type' => 'x.y',
            'priority' => 'ordinary',
            'title' => 'T2', 'body' => 'B2',
        ]);

        $headers = $this->auth();
        $this->getJson('/api/v1/notifications', $headers)->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/notifications/unread-count', $headers)->assertOk()
            ->assertJsonPath('data.unread', 1)
            ->assertJsonPath('data.critical_unacknowledged', 0);
    }

    public function test_read_marque_lu_et_show_le_fait_aussi(): void
    {
        $notification = AppNotification::create([
            'recipient_account_id' => $this->sub,
            'event_type' => 'x.y',
            'priority' => 'ordinary',
            'title' => 'T', 'body' => 'B',
        ]);

        $headers = $this->auth();
        $this->postJson("/api/v1/notifications/{$notification->id}/read", [], $headers)
            ->assertOk()->assertJsonPath('data.is_read', true);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_notification_d_un_autre_compte_est_refusee(): void
    {
        $foreign = AppNotification::create([
            'recipient_account_id' => (string) Str::uuid7(),
            'event_type' => 'x.y',
            'priority' => 'ordinary',
            'title' => 'T', 'body' => 'B',
        ]);

        $headers = $this->auth();
        $this->postJson("/api/v1/notifications/{$foreign->id}/read", [], $headers)
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_accuse_explicite_reserve_au_critique_et_emet_evenement(): void
    {
        $critical = $this->criticalNotification($this->sub);
        $headers = $this->auth();

        $this->postJson("/api/v1/notifications/{$critical->id}/acknowledge", [
            'comment' => 'Pris en compte par le magasinier',
        ], $headers)->assertOk()
            ->assertJsonPath('data.acknowledged_by', $this->sub);

        $fresh = $critical->fresh();
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertTrue($fresh->is_read);

        $this->assertTrue(OutboxMessage::where('event_type', 'notification.acknowledged')
            ->where('aggregate_id', $critical->id)->exists());
    }

    public function test_accuse_sur_non_critique_est_refuse(): void
    {
        $notification = AppNotification::create([
            'recipient_account_id' => $this->sub,
            'event_type' => 'x.y',
            'priority' => 'important',
            'title' => 'T', 'body' => 'B',
        ]);

        $this->postJson("/api/v1/notifications/{$notification->id}/acknowledge", [], $this->auth())
            ->assertStatus(422)->assertJsonPath('code', 'NOT_CRITICAL');
    }

    public function test_double_accuse_est_refuse(): void
    {
        $critical = $this->criticalNotification($this->sub);
        $headers = $this->auth();

        $this->postJson("/api/v1/notifications/{$critical->id}/acknowledge", [], $headers)->assertOk();
        $this->postJson("/api/v1/notifications/{$critical->id}/acknowledge", [], $headers)
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_ACKNOWLEDGED');
    }

    public function test_sans_permission_403(): void
    {
        $headers = $this->auth(['permissions' => ['asset.read']]);
        $this->getJson('/api/v1/notifications', $headers)->assertForbidden();
    }

    public function test_sans_token_401(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    // ─── Livraisons / retries ──────────────────────────────────────────

    public function test_echec_livraison_puis_retry_puis_abandon(): void
    {
        config(['notification.force_failure' => true]);

        $recipient = (string) Str::uuid7();
        $this->app->make(NotificationService::class)->processEvent(
            $this->envelope('maintenance.spare_part.below_threshold', [
                'part_reference' => 'PR-500',
                'quantity' => 1,
                'recipient_account_ids' => [$recipient],
            ])
        );

        $delivery = NotificationDelivery::where('channel', 'email')->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(1, $delivery->attempts);

        $service = $this->app->make(NotificationService::class);
        config(['notification.force_failure' => false]);

        // retry 1 : reussit → sent
        $result = $service->retryFailedDeliveries();
        $this->assertSame(1, $result['retried']);
        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        $this->assertSame(2, $delivery->attempts);
    }

    public function test_abandon_apres_trois_tentatives(): void
    {
        config(['notification.force_failure' => true]);
        $recipient = (string) Str::uuid7();
        $this->app->make(NotificationService::class)->processEvent(
            $this->envelope('maintenance.spare_part.below_threshold', [
                'part_reference' => 'PR-501',
                'quantity' => 1,
                'recipient_account_ids' => [$recipient],
            ])
        );

        $delivery = NotificationDelivery::where('channel', 'email')->sole();
        $delivery->update(['attempts' => 3]);

        $result = $this->app->make(NotificationService::class)->retryFailedDeliveries();
        $this->assertSame(1, $result['abandoned']);
        $this->assertSame(DeliveryStatus::Abandoned, $delivery->fresh()->status);

        $this->assertTrue(OutboxMessage::where('event_type', 'notification.delivery_failed')
            ->where('aggregate_id', $delivery->id)->exists());
    }

    // ─── Templates ─────────────────────────────────────────────────────

    public function test_templates_exposes_les_modeles_actifs(): void
    {
        $this->auth();
        app(NotificationService::class)->ensureDefaultTemplates();

        $this->getJson('/api/v1/notification-templates', $this->auth())->assertOk()
            ->assertJsonCount(3, 'data');
    }
}
