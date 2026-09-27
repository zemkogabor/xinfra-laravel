<?php

declare(strict_types=1);

return [
    // Sending is turned off when the URL or the key is empty (e.g. local development, tests)
    'url' => env('XINFRA_INGEST_URL'),
    'key' => env('XINFRA_INGEST_KEY'),

    // Required when sending is on. It must exist on the project in xInfra.
    'environment' => env('XINFRA_ENVIRONMENT'),

    // Minimum level to send
    'level' => env('XINFRA_LOG_LEVEL', 'warning'),

    // Version of the app, e.g. a git tag
    'release' => env('XINFRA_RELEASE'),

    // Events per request (at most 100) and the request timeout in seconds
    'max_batch_size' => 50,
    'timeout' => 2.0,

    // Send the id of the logged in user
    'capture_user' => true,

    // Events logged while serving these paths are not sent (Request::is() patterns, e.g. 'api/webhooks/*')
    'ignore_paths' => [],

    // Monolog processors for app specific data, e.g. tags. Class names, resolved from the container.
    'processors' => [],
];
