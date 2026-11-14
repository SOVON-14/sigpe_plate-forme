<?php

namespace App\Console\Commands;

use App\Services\ReportingEventHandler;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consommateur des evenements entrants (queue dediee
 * reporting-service.events).
 *
 *   php artisan sigpe:events:consume            # boucle infinie
 *   php artisan sigpe:events:consume --once     # un message puis sortie
 *   php artisan sigpe:events:consume --max=10   # N messages puis sortie
 */
class ConsumeEventsCommand extends Command
{
    protected $signature = 'sigpe:events:consume
                            {--once : Traite un seul message puis s\'arrête}
                            {--max= : Nombre maximum de messages a traiter}';

    protected $description = 'Consomme tous les evenements RabbitMQ (topic #) pour Reporting Service';

    private const QUEUE = 'reporting-service.events';

    private const ROUTING_KEYS = [
        '#',
    ];

    public function handle(ReportingEventHandler $handler): int
    {
        $connection = new AMQPStreamConnection(
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

        try {
            $channel = $connection->channel();
            $channel->exchange_declare(config('rabbitmq.exchange'), 'topic', false, true, false);
            $channel->queue_declare(self::QUEUE, false, true, false, false);

            foreach (self::ROUTING_KEYS as $key) {
                $channel->queue_bind(self::QUEUE, config('rabbitmq.exchange'), $key);
            }

            $channel->basic_qos(0, 1, false);

            $processed = 0;
            $max = $this->option('max') !== null ? (int) $this->option('max') : null;
            $once = (bool) $this->option('once');

            $channel->basic_consume(self::QUEUE, '', false, false, false, false, function (AMQPMessage $message) use ($channel, $handler, &$processed, $max, $once) {
                try {
                    $envelope = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    $result = $handler->handle($envelope);
                    $channel->basic_ack($message->getDeliveryTag());
                    $this->info("[{++$processed}] {$envelope['event_type']} -> {$result}");
                } catch (\Throwable $e) {
                    $this->error('Erreur traitement message : '.$e->getMessage());
                    $channel->basic_nack($message->getDeliveryTag(), false, true);
                }

                if ($once || ($max !== null && $processed >= $max)) {
                    $channel->stop_consume();
                }
            });

            while ($channel->is_consuming()) {
                $channel->wait(null, false, 5);
            }

            return self::SUCCESS;
        } finally {
            try {
                $connection->close();
            } catch (\Throwable) {
                // connexion deja fermee
            }
        }
    }
}
