<?php

declare(strict_types=1);

namespace XInfra\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\ServiceProvider;

final class XInfraServiceProvider extends ServiceProvider
{
    /**
     * Octane keeps the process alive between requests, so the buffer is sent after each one.
     * Plain strings, Octane is not a dependency.
     */
    private const array OCTANE_EVENTS = [
        'Laravel\Octane\Events\RequestTerminated',
        'Laravel\Octane\Events\TaskTerminated',
        'Laravel\Octane\Events\TickTerminated',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/xinfra.php', 'xinfra');
        $this->app->singleton(ClientRegistry::class);

        // The "xinfra" channel works without editing config/logging.php
        $config = $this->app->make(Repository::class);

        if (!$config->has('logging.channels.xinfra')) {
            $config->set('logging.channels.xinfra', [
                'driver' => 'custom',
                'via' => CreateXInfraLogger::class,
            ]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/xinfra.php' => $this->app->configPath('xinfra.php')], 'xinfra-config');
        }

        // A queue worker runs for days. Without this, events wait in memory until a batch is full,
        // and are lost when the worker restarts.
        $flush = function (): void {
            $this->app->make(ClientRegistry::class)->flush();
        };

        $events = $this->app->make(Dispatcher::class);
        $events->listen([Looping::class, WorkerStopping::class, ...self::OCTANE_EVENTS], $flush);
    }
}
