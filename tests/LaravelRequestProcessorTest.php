<?php

declare(strict_types=1);

namespace XInfra\Laravel\Tests;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use XInfra\Event;
use XInfra\Laravel\LaravelRequestProcessor;
use XInfra\Level;

final class LaravelRequestProcessorTest extends TestCase
{
    public function testNothingOutsideOfHttpRequests(): void
    {
        $processor = new LaravelRequestProcessor(static fn(): ?Request => null, $this->getApp()->make(AuthFactory::class), ['*']);

        $event = $processor->process(new Event(Level::Error, 'Job failed'));

        self::assertNotNull($event);
        self::assertNull($event->request);
        self::assertNull($event->ip);
        self::assertNull($event->user);
    }

    public function testExistingFieldsAreKept(): void
    {
        $request = Request::create('https://shop.example.com/cart', 'POST');
        $processor = new LaravelRequestProcessor(static fn(): Request => $request, null);
        $event = new Event(Level::Error, 'Failed');
        $event->request = ['url' => 'https://other.example.com', 'method' => 'GET'];

        $processed = $processor->process($event);

        self::assertNotNull($processed);
        self::assertSame(['url' => 'https://other.example.com', 'method' => 'GET'], $processed->request);
        self::assertSame('127.0.0.1', $processed->ip);
    }
}
