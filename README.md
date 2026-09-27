# xinfra-laravel

Laravel integration for **xInfra**, a log collection and monitoring app. It adds an `xinfra`
log channel that sends your logs and exceptions to the xInfra ingest API.

Built on [xinfra-php](https://github.com/zemkogabor/xinfra-php).

- Works with the normal `Log` facade and the exception handler, no code changes needed
- Logs are buffered and sent in one request at the end of the HTTP request
- Queue workers and Octane send their buffer after every job and request
- Adds the request URL, method, client IP, user agent and the logged in user id
- Sending never breaks your app: the timeout is short and every error is swallowed

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13

## Installation

```bash
composer require zemkogabor/xinfra-laravel
```

The service provider is registered automatically.

Set the environment variables:

```dotenv
XINFRA_INGEST_URL=https://xinfra.example.com/api/ingest/events
XINFRA_INGEST_KEY=your-key
XINFRA_ENVIRONMENT=prod
XINFRA_LOG_LEVEL=warning
# Optional, the version of your app
XINFRA_RELEASE=
```

Add the `xinfra` channel to your log stack:

```dotenv
LOG_STACK=single,xinfra
```

That's it. If the URL or the key is empty, the channel does nothing, so you can keep it in the
stack in development and tests too.

## Configuration

To change the defaults, publish the config file:

```bash
php artisan vendor:publish --tag=xinfra-config
```

```php
// config/xinfra.php
return [
    'url' => env('XINFRA_INGEST_URL'),
    'key' => env('XINFRA_INGEST_KEY'),
    'environment' => env('XINFRA_ENVIRONMENT'),
    'level' => env('XINFRA_LOG_LEVEL', 'warning'),
    'release' => env('XINFRA_RELEASE'),
    'max_batch_size' => 50,
    'timeout' => 2.0,
    'capture_user' => true,
    'ignore_paths' => [],
    'processors' => [],
];
```

| Key | Description |
| --- | --- |
| `url`, `key` | The ingest endpoint and the key of the log client. Empty = sending is off. |
| `environment` | Environment name, required when sending is on. It must exist on the project in xInfra. |
| `level` | Minimum level to send |
| `release` | Version of your app, e.g. a git tag |
| `max_batch_size` | Events per request, at most 100 |
| `timeout` | Seconds, for connecting and for the whole request |
| `capture_user` | Send the id of the logged in user |
| `ignore_paths` | Logs written while serving these paths are not sent. `Request::is()` patterns, e.g. `'api/webhooks/*'` |
| `processors` | Monolog processors for your own data, see below |

You can also define the channel yourself in `config/logging.php`. Keys set there override
`config/xinfra.php`:

```php
'xinfra' => [
    'driver' => 'custom',
    'via' => XInfra\Laravel\CreateXInfraLogger::class,
    'level' => 'error',
],
```

## Tags

Tags are short key-value pairs you can filter by in xInfra. Add them with a Monolog processor
that writes `extra['tags']`:

```php
namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class TenantTagProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId !== null) {
            $record->extra['tags'] = [...($record->extra['tags'] ?? []), 'tenant' => (string) $tenantId];
        }

        return $record;
    }
}
```

```php
// config/xinfra.php
'processors' => [App\Logging\TenantTagProcessor::class],
```

## What is sent

- The log level, the message (with `{placeholder}` values filled in) and the context
- A `Throwable` in the `exception` context key as the exception, with file, line and stack trace.
  Laravel's exception handler already logs exceptions this way.
- During HTTP requests: URL, method, client IP (`Request::ip()`, so your trusted proxy settings
  apply), user agent, and the id of the logged in user

The user id is sent only if the user was already loaded during the request. The package never
loads the user itself, because that could query the database while logging a database error.

## Long running processes

Logs are buffered and sent when a batch is full or when the process ends. The package also
sends the buffer:

- in queue workers, after every job (and when the worker stops)
- in Octane, after every request, task and tick

For your own long running commands, send the buffer yourself:

```php
app(XInfra\Laravel\ClientRegistry::class)->flush();
```

## Testing

With an empty URL or key (the default), nothing is sent and nothing is buffered. When sending is
on, requests go through the Laravel HTTP client, so `Http::fake()` catches them.

## Development

```bash
composer install
composer check   # code style, static analysis, tests
```

## License

MIT
