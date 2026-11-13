<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'sigpe'),
    'password' => env('RABBITMQ_PASSWORD', ''),
    'vhost' => env('RABBITMQ_VHOST', 'sigpe'),
    'exchange' => env('RABBITMQ_EXCHANGE', 'sigpe.events.v1'),
    'connect_timeout' => (int) env('RABBITMQ_CONNECT_TIMEOUT', 5),
    'read_write_timeout' => (int) env('RABBITMQ_RW_TIMEOUT', 10),
    'confirm_timeout' => (int) env('RABBITMQ_CONFIRM_TIMEOUT', 10),
    'max_attempts' => (int) env('RABBITMQ_MAX_ATTEMPTS', 5),
    'batch_size' => (int) env('RABBITMQ_BATCH_SIZE', 100),
    'backoff_base_seconds' => (int) env('RABBITMQ_BACKOFF_BASE', 5),
];
