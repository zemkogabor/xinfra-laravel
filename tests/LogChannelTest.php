<?php

declare(strict_types=1);

namespace XInfra\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use RuntimeException;
use XInfra\Laravel\CreateXInfraLogger;
use XInfra\Laravel\Tests\Fixtures\TenantTagProcessor;

final class LogChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function testChannelIsRegisteredByDefault(): void
    {
        self::assertSame(['driver' => 'custom', 'via' => CreateXInfraLogger::class], config('logging.channels.xinfra'));
        self::assertSame('warning', config('xinfra.level'));
    }

    public function testDisabledChannelDoesNotBuffer(): void
    {
        $channel = Log::channel('xinfra');
        self::assertInstanceOf(LaravelLogger::class, $channel);
        $logger = $channel->getLogger();

        self::assertInstanceOf(Logger::class, $logger);
        self::assertInstanceOf(NullHandler::class, $logger->getHandlers()[0]);
        self::assertCount(0, $this->getRegistry()->getClients());
    }

    public function testErrorIsSentWithTheException(): void
    {
        $this->enableSending();
        $exception = new RuntimeException('Import failed');

        Log::channel('xinfra')->error('Import of {file} failed', ['file' => 'products.csv', 'exception' => $exception]);
        Log::channel('xinfra')->info('Below the minimum level');

        Http::assertNothingSent();

        $this->getRegistry()->flush();

        $requests = $this->getSentRequests();
        self::assertCount(1, $requests);
        self::assertSame(self::INGEST_URL, $requests[0]->url());
        self::assertTrue($requests[0]->hasHeader('X-XInfra-Key', 'test-key'));
        self::assertTrue($requests[0]->hasHeader('Content-Type', 'application/json'));

        $events = $this->getSentEvents();
        self::assertCount(1, $events);
        self::assertSame('error', $events[0]['level']);
        self::assertSame('Import of products.csv failed', $events[0]['message']);
        self::assertSame('prod', $events[0]['environment']);
        self::assertSame(['file' => 'products.csv'], $events[0]['context']);
        self::assertIsArray($events[0]['exception']);
        self::assertSame(RuntimeException::class, $events[0]['exception']['type']);
        self::assertSame($exception->getLine(), $events[0]['exception']['line']);
    }

    public function testRequestAndUserData(): void
    {
        $this->enableSending();
        Route::get('/orders/{id}', function (): string {
            Auth::guard()->setUser(new GenericUser(['id' => 7]));
            Log::channel('xinfra')->warning('Order is locked');

            return 'ok';
        });

        $this->get('/orders/5?tab=items', ['User-Agent' => 'TestAgent'])->assertOk();
        $this->getRegistry()->flush();

        $event = $this->getSentEvents()[0];
        self::assertSame(['url' => 'http://localhost/orders/5?tab=items', 'method' => 'GET'], $event['request']);
        self::assertSame('TestAgent', $event['userAgent']);
        self::assertSame(['id' => '7'], $event['user']);
        self::assertSame('127.0.0.1', $event['ip']);
    }

    public function testUserIsLeftOutWhenTurnedOff(): void
    {
        $this->enableSending();
        config(['xinfra.capture_user' => false]);
        Route::get('/orders', function (): string {
            Auth::guard()->setUser(new GenericUser(['id' => 7]));
            Log::channel('xinfra')->warning('Order is locked');

            return 'ok';
        });

        $this->get('/orders')->assertOk();
        $this->getRegistry()->flush();

        self::assertArrayNotHasKey('user', $this->getSentEvents()[0]);
    }

    public function testIgnoredPaths(): void
    {
        $this->enableSending();
        config(['xinfra.ignore_paths' => ['api/ingest/*']]);
        Route::post('/api/ingest/events', function (): string {
            Log::channel('xinfra')->error('Storage is down');

            return 'ok';
        });

        $this->post('/api/ingest/events')->assertOk();
        $this->getRegistry()->flush();

        Http::assertNothingSent();
    }

    public function testProcessorsFromTheConfig(): void
    {
        $this->enableSending();
        config(['xinfra.processors' => [TenantTagProcessor::class]]);

        Log::channel('xinfra')->error('Failed');
        $this->getRegistry()->flush();

        self::assertSame(['tenant' => '12'], $this->getSentEvents()[0]['tags']);
    }

    public function testChannelConfigOverridesThePackageConfig(): void
    {
        $this->enableSending();
        config(['logging.channels.xinfra' => [
            'driver' => 'custom',
            'via' => CreateXInfraLogger::class,
            'level' => 'error',
            'environment' => 'staging',
            'release' => 'v3',
        ]]);

        Log::channel('xinfra')->warning('Below the channel level');
        Log::channel('xinfra')->error('Sent');
        $this->getRegistry()->flush();

        $events = $this->getSentEvents();
        self::assertCount(1, $events);
        self::assertSame('staging', $events[0]['environment']);
        self::assertSame('v3', $events[0]['release']);
    }

    public function testQueueWorkerLoopFlushesTheBuffer(): void
    {
        $this->enableSending();

        Log::channel('xinfra')->error('Job failed');
        Http::assertNothingSent();

        event(new Looping('redis', 'default'));

        Http::assertSentCount(1);
    }

    public function testOctaneRequestEndFlushesTheBuffer(): void
    {
        $this->enableSending();

        Log::channel('xinfra')->error('Request failed');
        $this->getApp()->make(Dispatcher::class)->dispatch('Laravel\Octane\Events\RequestTerminated');

        Http::assertSentCount(1);
    }

    public function testStackChannel(): void
    {
        $this->enableSending();
        config([
            'logging.default' => 'stack',
            'logging.channels.stack' => ['driver' => 'stack', 'channels' => ['single', 'xinfra']],
        ]);

        Log::error('Through the stack');
        $this->getRegistry()->flush();

        self::assertSame('Through the stack', $this->getSentEvents()[0]['message']);
    }

    public function testSendingErrorsDoNotBreakTheApp(): void
    {
        $this->enableSending();
        Http::fake(fn() => throw new RuntimeException('Connection refused'));

        Log::channel('xinfra')->error('Lost');
        $this->getRegistry()->flush();

        self::assertCount(0, $this->getRegistry()->getClients()[0]->getPendingEvents());
    }

    public function testInvalidConfigIsAnError(): void
    {
        $this->enableSending();
        config(['xinfra.processors' => 'App\Logging\TenantTagProcessor']);

        $this->expectException(InvalidArgumentException::class);

        $this->getApp()->make(CreateXInfraLogger::class)(['driver' => 'custom']);
    }
}
