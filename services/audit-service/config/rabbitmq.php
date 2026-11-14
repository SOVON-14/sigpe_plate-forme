<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'sigpe'),
    'password' => env('RABBITMQ_PASSWORD', ''),
    'vhost' => env('RABBITMQ_VHOST', 'sigpe'),
    'exchange' => env('RABBITMQ_EXCHANGE', 'sigpe.events.v1'),
    'confirm_timeout' => (float) env('RABBITMQ_CONFIRM_TIMEOUT', 5.0),
    'connect_timeout' => (float) env('RABBITMQ_CONNECT_TIMEOUT', 5.0),
    'read_write_timeout' => (float) env('RABBITMQ_READ_WRITE_TIMEOUT', 30.0),
    'max_attempts' => (int) env('RABBITMQ_MAX_ATTEMPTS', 5),
    'batch_size' => (int) env('RABBITMQ_BATCH_SIZE', 100),
    'backoff_base_seconds' => (int) env('RABBITMQ_BACKOFF_BASE', 5),
];
