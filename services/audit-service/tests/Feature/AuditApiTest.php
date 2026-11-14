<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\InboxMessage;
use App\Services\AuditEventHandler;
use App\Services\AuditService;
use App\Services\TokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    private string $institutionId;

    private string $otherInstitutionId;

    private string $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institutionId = (string) Str::uuid7();
        $this->otherInstitutionId = (string) Str::uuid7();
        $this->sub = (string) Str::uuid7();
    }

    private function auth(array $claims = []): array
    {
        $merged = array_merge([
            'sub' => $this->sub,
            'permissions' => ['audit.read', 'audit.verify'],
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

    private function envelope(array $overrides = [], array $data = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid7(),
            'event_type' => 'asset.registered',
            'event_version' => 1,
            'occurred_at' => now()->toIso8601String(),
            'producer' => 'asset-service',
            'aggregate_id' => (string) Str::uuid7(),
            'correlation_id' => (string) Str::uuid7(),
            'causation_id' => (string) Str::uuid7(),
            'institution_id' => $this->institutionId,
            'actor' => ['type' => 'user', 'id' => $this->sub],
            'data' => $data,
        ], $overrides);
    }

    private function recordEvent(array $overrides = [], array $data = []): AuditEntry
    {
        return $this->app->make(AuditService::class)
            ->recordFromEnvelope($this->envelope($overrides, $data));
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('service', 'audit-service');
    }

    public function test_entries_require_authentication(): void
    {
        $this->getJson('/api/v1/audit-entries')->assertUnauthorized();
    }

    public function test_entries_require_permission(): void
    {
        $this->getJson('/api/v1/audit-entries', $this->auth(['permissions' => []]))
            ->assertForbidden();
    }

    public function test_event_is_recorded_with_chain(): void
    {
        $entry = $this->recordEvent(data: [
            'operation' => 'asset.registered',
            'resource_type' => 'asset',
            'result' => 'success',
            'reason' => 'Enregistrement initial',
        ]);

        $this->assertDatabaseHas('audit_entries', [
            'id' => $entry->id,
            'producer' => 'asset-service',
            'event_type' => 'asset.registered',
            'institution_id' => $this->institutionId,
            'actor_id' => $this->sub,
            'sensitivity' => 'sensitive',
        ]);
        $this->assertNull($entry->previous_hash);
        $this->assertSame(64, strlen($entry->entry_hash));
    }

    public function test_second_entry_links_to_first(): void
    {
        $first = $this->recordEvent();
        $second = $this->recordEvent();

        $this->assertSame($first->entry_hash, $second->fresh()->previous_hash);
    }

    public function test_sensitive_entry_produces_outbox_event(): void
    {
        $this->recordEvent(['event_type' => 'movement.transfer_completed']);

        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'audit.entry_recorded',
        ]);
    }

    public function test_normal_entry_produces_no_outbox_event(): void
    {
        $this->recordEvent(['event_type' => 'reporting.dashboard_viewed']);

        $this->assertDatabaseMissing('outbox_messages', [
            'event_type' => 'audit.entry_recorded',
        ]);
    }

    public function test_list_filters_and_pagination(): void
    {
        $this->recordEvent(['event_type' => 'asset.registered'], ['resource_type' => 'asset']);
        $this->recordEvent(['event_type' => 'inventory.finding_recorded'], ['resource_type' => 'finding']);
        $this->recordEvent(['producer' => 'movement-service', 'event_type' => 'movement.loan_returned']);

        $response = $this->getJson('/api/v1/audit-entries?producer=asset-service&event_type=asset.registered', $this->auth())
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('asset.registered', $response->json('data.0.event_type'));
    }

    public function test_abac_scopes_entries_by_institution(): void
    {
        $this->recordEvent(); // institution_id = $this->institutionId
        $foreign = $this->recordEvent(['institution_id' => $this->otherInstitutionId]);

        $response = $this->getJson('/api/v1/audit-entries', $this->auth())->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertNotContains($foreign->id, $ids->all());
        $this->assertGreaterThanOrEqual(1, $ids->count());
    }

    public function test_national_auditor_sees_all_institutions(): void
    {
        $this->recordEvent(['institution_id' => $this->otherInstitutionId]);

        $response = $this->getJson('/api/v1/audit-entries', $this->auth(['institution_id' => null]))
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_show_returns_detail_with_payload(): void
    {
        $entry = $this->recordEvent(data: ['custom_field' => 'valeur-test']);

        $this->getJson('/api/v1/audit-entries/'.$entry->id, $this->auth())
            ->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.payload.data.custom_field', 'valeur-test')
            ->assertJsonStructure(['data' => ['old_value', 'new_value', 'payload', 'entry_hash']]);
    }

    public function test_show_foreign_institution_denied(): void
    {
        $foreign = $this->recordEvent(['institution_id' => $this->otherInstitutionId]);

        $this->getJson('/api/v1/audit-entries/'.$foreign->id, $this->auth())
            ->assertForbidden();
    }

    public function test_verify_chain_valid(): void
    {
        $this->recordEvent();
        $this->recordEvent();
        $this->recordEvent();

        $this->getJson('/api/v1/audit-entries/chain/verify', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.entries_checked', 3);

        // La verification est elle-meme journalisee via l'outbox.
        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'audit.integrity_check_completed',
        ]);
    }

    public function test_verify_chain_detects_tampering(): void
    {
        $entry = $this->recordEvent();
        $this->recordEvent();

        // Simulation d'une alteration : modification directe en base
        // (contournement applicatif — exactement ce que la chaine doit detecter).
        AuditEntry::where('id', $entry->id)->update(['reason' => 'valeur-alteree']);

        $this->getJson('/api/v1/audit-entries/chain/verify', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.first_invalid_entry_id', $entry->id);
    }

    public function test_verify_chain_detects_deletion(): void
    {
        $this->recordEvent();
        $second = $this->recordEvent();

        // Suppression du premier maillon : le second pointe vers un
        // previous_hash qui ne correspond plus au predecesseur reel.
        AuditEntry::where('seq', 1)->delete();

        $result = $this->getJson('/api/v1/audit-entries/chain/verify', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.valid', false);

        $this->assertSame($second->id, $result->json('data.first_invalid_entry_id'));
    }

    public function test_stats_endpoint(): void
    {
        $this->recordEvent(['event_type' => 'asset.registered']);
        $this->recordEvent(['event_type' => 'movement.transfer_completed']);

        $this->getJson('/api/v1/audit-entries/stats', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.total_entries', 2)
            ->assertJsonStructure(['data' => ['by_result', 'by_producer', 'by_operation', 'daily']]);
    }

    public function test_event_handler_deduplicates(): void
    {
        $handler = $this->app->make(AuditEventHandler::class);
        $envelope = $this->envelope(['event_type' => 'asset.registered']);

        $this->assertSame('recorded', $handler->handle($envelope));
        $this->assertSame('duplicate', $handler->handle($envelope));
        $this->assertSame(1, AuditEntry::count());
        $this->assertSame(1, InboxMessage::count());
    }

    public function test_event_handler_ignores_missing_event_id(): void
    {
        $handler = $this->app->make(AuditEventHandler::class);

        $this->assertSame('ignored', $handler->handle(['event_type' => 'x.y']));
    }
}
