<?php

declare(strict_types=1);

namespace XInfra\Laravel;

use Illuminate\Http\Client\Factory;
use XInfra\Transport\Transport;

/**
 * Sends through the Laravel HTTP client, so Http::fake() works in tests.
 */
final class LaravelHttpTransport implements Transport
{
    public function __construct(private readonly Factory $http) {}

    public function send(string $url, string $key, string $body, float $timeout): void
    {
        $this->http
            ->connectTimeout($timeout)
            ->timeout($timeout)
            ->withHeaders(['X-XInfra-Key' => $key])
            ->withBody($body, 'application/json')
            ->post($url);
    }
}
