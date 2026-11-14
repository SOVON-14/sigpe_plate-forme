<?php

namespace App\Services;

use App\Models\OutboxMessage;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMqPublisher
{
    private ?AMQPStreamConnection $connection = null;

    public function publish(OutboxMessage $message): void
    {
        $this->connect();
        $channel = $this->connection->channel();
        $channel->confirm_select();
        $channel->exchange_declare(config('rabbitmq.exchange'), 'topic', false, true, false);

        try {
            $channel->basic_publish(
                new AMQPMessage(
                    json_encode($this->envelope($message), JSON_THROW_ON_ERROR),
                    [
                        'content_type' => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                        'message_id' => $message->id,
                        'timestamp' => $message->occurred_at->getTimestamp(),
                        'application_headers' => new AMQPTable([
                            'event_type' => $message->event_type,
                            'event_version' => $message->event_version,
                            'correlation_id' => $message->correlation_id,
                        ]),
                    ],
                ),
                config('rabbitmq.exchange'),
                $this->routingKey($message),
                true,
            );

            $channel->waitForPendingAcks((float) config('rabbitmq.confirm_timeout'));
        } finally {
            $channel->close();
        }
    }

    public function envelope(OutboxMessage $message): array
    {
        return [
            'event_id' => $message->id,
            'event_type' => $message->event_type,
            'event_version' => $message->event_version,
            'occurred_at' => $message->occurred_at->toIso8601String(),
            'producer' => OutboxRecorder::PRODUCER,
            'aggregate_id' => $message->aggregate_id,
            'correlation_id' => $message->correlation_id,
            'causation_id' => $message->causation_id,
            'institution_id' => $message->institution_id,
            'actor' => $message->actor,
            'data' => $message->payload,
        ];
    }

    public function routingKey(OutboxMessage $message): string
    {
        return $message->event_type . '.v' . $message->event_version;
    }

    public function markPublished(OutboxMessage $message): void
    {
        $message->forceFill([
            'status' => OutboxMessage::STATUS_PUBLISHED,
            'published_at' => now(),
            'next_attempt_at' => null,
        ])->save();
    }

    public function markFailed(OutboxMessage $message, \Throwable $e): void
    {
        $attempts = $message->attempts + 1;
        $max = (int) config('rabbitmq.max_attempts', 5);

        $message->forceFill([
            'attempts' => $attempts,
            'status' => $attempts >= $max ? OutboxMessage::STATUS_FAILED : OutboxMessage::STATUS_PENDING,
            'next_attempt_at' => $attempts >= $max
                ? null
                : now()->addSeconds(((int) config('rabbitmq.backoff_base_seconds', 5)) * (2 ** ($attempts - 1))),
        ])->save();

        report($e);
    }

    private function connect(): void
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            return;
        }

        $this->connection = new AMQPStreamConnection(
            config('rabbitmq.host'),
            (int) config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password'),
            config('rabbitmq.vhost'),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            (float) config('rabbitmq.connect_timeout'),
            (float) config('rabbitmq.read_write_timeout'),
        );
    }

    public function disconnect(): void
    {
        try {
            $this->connection?->close();
        } finally {
            $this->connection = null;
        }
    }
}
