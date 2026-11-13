<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\OutboxMessage;
use App\Services\OutboxRecorder;
use App\Services\RabbitMqPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class OutboxPublisherTest extends TestCase
{
    use RefreshDatabase;

    private RabbitMqPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = app(RabbitMqPublisher::class);
    }

    private function makeMessage(): OutboxMessage
    {
        $institution = Institution::create([
            'code' => 'MIN-OUTBOX',
            'name' => 'Ministère Outbox',
            'type' => 'ministere',
            'status' => 'active',
        ]);

        return app(OutboxRecorder::class)->record(
            'organization.institution.created',
            'institution',
            $institution->id,
            ['institution_id' => $institution->id, 'code' => 'MIN-OUTBOX'],
            'corr-1',
            null,
            $institution->id,
            ['type' => 'user', 'id' => (string) \Illuminate\Support\Str::uuid7()],
        );
    }

    public function test_envelope_matches_contract_fields(): void
    {
        $envelope = $this->publisher->envelope($this->makeMessage());

        foreach (['event_id', 'event_type', 'event_version', 'occurred_at', 'producer', 'aggregate_id', 'correlation_id', 'causation_id', 'institution_id', 'data'] as $field) {
            $this->assertArrayHasKey($field, $envelope);
        }

        $this->assertSame('organization-service', $envelope['producer']);
        $this->assertSame('organization.institution.created', $envelope['event_type']);
        $this->assertSame(1, $envelope['event_version']);
        $this->assertSame('corr-1', $envelope['correlation_id']);
        $this->assertSame('corr-1', $envelope['causation_id']);
        $this->assertArrayHasKey('institution_id', $envelope['data']);
    }

    public function test_routing_key_includes_version(): void
    {
        $this->assertSame('organization.institution.created.v1', $this->publisher->routingKey($this->makeMessage()));
    }

    public function test_mark_failed_applies_exponential_backoff_then_fails(): void
    {
        config()->set('rabbitmq.max_attempts', 2);
        config()->set('rabbitmq.backoff_base_seconds', 5);

        $message = $this->makeMessage();

        $this->publisher->markFailed($message, new RuntimeException('broker down'));
        $fresh = $message->fresh();
        $this->assertSame(OutboxMessage::STATUS_PENDING, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertTrue($fresh->next_attempt_at->isFuture());
        $this->assertFalse(OutboxMessage::claimable()->whereKey($fresh->id)->exists());

        $this->publisher->markFailed($fresh, new RuntimeException('broker down'));
        $fresh = $fresh->fresh();
        $this->assertSame(OutboxMessage::STATUS_FAILED, $fresh->status);
        $this->assertSame(2, $fresh->attempts);
    }

    public function test_claimable_includes_due_messages(): void
    {
        $message = $this->makeMessage();
        $this->assertTrue(OutboxMessage::claimable()->whereKey($message->id)->exists());

        $message->forceFill(['next_attempt_at' => Carbon::now()->addHour()])->save();
        $this->assertFalse(OutboxMessage::claimable()->whereKey($message->id)->exists());
    }
}
