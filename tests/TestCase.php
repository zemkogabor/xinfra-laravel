<?php

declare(strict_types=1);

namespace XInfra\Laravel\Tests;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RuntimeException;
use XInfra\Laravel\ClientRegistry;
use XInfra\Laravel\XInfraServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    public const string INGEST_URL = 'https://xinfra.example.com/api/ingest/events';

    /**
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [XInfraServiceProvider::class];
    }

    protected function enableSending(): void
    {
        config([
            'xinfra.url' => self::INGEST_URL,
            'xinfra.key' => 'test-key',
            'xinfra.environment' => 'prod',
            'xinfra.level' => 'warning',
        ]);
    }

    protected function getApp(): ApplicationContract
    {
        if ($this->app === null) {
            throw new RuntimeException('The application is not created yet.');
        }

        return $this->app;
    }

    protected function getRegistry(): ClientRegistry
    {
        return $this->getApp()->make(ClientRegistry::class);
    }

    /**
     * Requests sent to the ingest API, see Http::fake().
     *
     * @return list<HttpRequest>
     */
    protected function getSentRequests(): array
    {
        $requests = [];

        foreach (Http::recorded()->all() as $pair) {
            self::assertIsArray($pair);
            self::assertInstanceOf(HttpRequest::class, $pair[0]);
            $requests[] = $pair[0];
        }

        return $requests;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function getSentEvents(): array
    {
        $events = [];

        foreach ($this->getSentRequests() as $request) {
            /** @var array{events: list<array<string, mixed>>} $body */
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            array_push($events, ...$body['events']);
        }

        return $events;
    }
}
