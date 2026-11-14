<?php

namespace App\Console\Commands;

use App\Jobs\PublishOutboxBatch;
use Illuminate\Console\Command;

class PublishOutboxCommand extends Command
{
    protected $signature = 'sigpe:outbox:publish {--sync : Exécuter la publication dans le process courant}';

    protected $description = 'Publie les messages Outbox en attente vers RabbitMQ';

    public function handle(): int
    {
        if ($this->option('sync')) {
            PublishOutboxBatch::dispatchSync();
            $this->info('Batch Outbox publié (sync).');

            return self::SUCCESS;
        }

        PublishOutboxBatch::dispatch();
        $this->info('Batch Outbox mis en file.');

        return self::SUCCESS;
    }
}
