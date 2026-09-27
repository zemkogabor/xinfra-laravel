<?php

declare(strict_types=1);

namespace XInfra\Laravel;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Monolog\Processor\ProcessorInterface;
use Monolog\Processor\PsrLogMessageProcessor;
use XInfra\Client;
use XInfra\Config;
use XInfra\Level;
use XInfra\Monolog\XInfraHandler;

/**
 * Factory of the "xinfra" log channel ('driver' => 'custom').
 *
 * Settings come from config/xinfra.php. Keys set on the log channel itself override them.
 */
final class CreateXInfraLogger
{
    /** Keys of the channel config that belong to Laravel, not to this package. */
    private const array CHANNEL_KEYS = ['driver', 'via', 'name', 'tap'];

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly ClientRegistry $registry,
    ) {}

    /**
     * @param array<string, mixed> $channelConfig
     */
    public function __invoke(array $channelConfig): Logger
    {
        $packageConfig = $this->config->get('xinfra', []);

        if (!is_array($packageConfig)) {
            throw new InvalidArgumentException('The xinfra config must be an array.');
        }

        $settings = array_merge($packageConfig, array_diff_key($channelConfig, array_flip(self::CHANNEL_KEYS)));
        $config = $this->buildConfig($settings);

        // No buffering at all when sending is off
        if (!$config->isEnabled()) {
            return new Logger('xinfra', [new NullHandler()]);
        }

        $client = new Client($config, new LaravelHttpTransport($this->app->make(HttpFactory::class)), [
            new LaravelRequestProcessor(
                $this->resolveRequest(...),
                self::getBool($settings, 'capture_user', true) ? $this->app->make(AuthFactory::class) : null,
                self::getStringList($settings, 'ignore_paths'),
            ),
        ]);
        $this->registry->register($client);

        return new Logger('xinfra', [new XInfraHandler($client)], [new PsrLogMessageProcessor(), ...$this->buildProcessors($settings)]);
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private function buildConfig(array $settings): Config
    {
        $level = self::getString($settings, 'level');
        $timeout = $settings['timeout'] ?? 2.0;
        $maxBatchSize = $settings['max_batch_size'] ?? 50;

        if (!is_int($maxBatchSize)) {
            throw new InvalidArgumentException('xinfra.max_batch_size must be an integer.');
        }

        if (!is_int($timeout) && !is_float($timeout)) {
            throw new InvalidArgumentException('xinfra.timeout must be a number.');
        }

        return new Config(
            url: self::getString($settings, 'url'),
            key: self::getString($settings, 'key'),
            environment: self::getString($settings, 'environment'),
            minLevel: $level !== null && trim($level) !== '' ? Level::fromName($level) : Level::Warning,
            release: self::getString($settings, 'release'),
            maxBatchSize: $maxBatchSize,
            timeout: (float) $timeout,
        );
    }

    /**
     * @param array<array-key, mixed> $settings
     * @return list<ProcessorInterface|callable>
     */
    private function buildProcessors(array $settings): array
    {
        $processors = [];

        foreach (self::getStringList($settings, 'processors') as $class) {
            $processor = $this->app->make($class);

            if (!$processor instanceof ProcessorInterface && !is_callable($processor)) {
                throw new InvalidArgumentException('The xinfra processor must be a Monolog processor: ' . $class);
            }

            $processors[] = $processor;
        }

        return $processors;
    }

    /**
     * The HTTP request, or null in queue workers and commands. Tests run on the command line
     * too, there the test request counts.
     */
    private function resolveRequest(): ?Request
    {
        if ($this->app->runningInConsole() && !$this->app->runningUnitTests()) {
            return null;
        }

        if (!$this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        return $request instanceof Request ? $request : null;
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private static function getString(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException('xinfra.' . $key . ' must be a string.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private static function getBool(array $settings, string $key, bool $default): bool
    {
        $value = $settings[$key] ?? $default;

        if (!is_bool($value)) {
            throw new InvalidArgumentException('xinfra.' . $key . ' must be a boolean.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $settings
     * @return list<string>
     */
    private static function getStringList(array $settings, string $key): array
    {
        $value = $settings[$key] ?? [];

        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('xinfra.' . $key . ' must be a list.');
        }

        $result = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('xinfra.' . $key . ' must contain strings.');
            }

            $result[] = $item;
        }

        return $result;
    }
}
