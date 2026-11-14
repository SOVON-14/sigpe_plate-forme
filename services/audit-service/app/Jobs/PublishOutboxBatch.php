<?php

namespace App\Jobs;

use App\Models\OutboxMessage;
use App\Services\RabbitMqPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishOutboxBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(RabbitMqPublisher $publisher): void
    {
        $messages = OutboxMessage::claimable()
            ->limit((int) config('rabbitmq.batch_size', 100))
            ->get();

        foreach ($messages as $message) {
            try {
                $publisher->publish($message);
                $publisher->markPublished($message);
            } catch (Throwable $e) {
                $publisher->markFailed($message, $e);
            }
        }

        $publisher->disconnect();
    }
}
