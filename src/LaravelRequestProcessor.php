<?php

declare(strict_types=1);

namespace XInfra\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Throwable;
use XInfra\Event;
use XInfra\EventProcessor;

/**
 * Adds the current HTTP request and the logged in user to the event.
 */
final class LaravelRequestProcessor implements EventProcessor
{
    /**
     * @param Closure(): ?Request $requestResolver Returns null outside of HTTP requests (queue, commands)
     * @param list<string> $ignorePaths Request::is() patterns
     */
    public function __construct(
        private readonly Closure $requestResolver,
        private readonly ?AuthFactory $auth,
        private readonly array $ignorePaths = [],
    ) {}

    public function process(Event $event): ?Event
    {
        $request = ($this->requestResolver)();

        if ($request === null) {
            return $event;
        }

        if (count($this->ignorePaths) > 0 && $request->is(...$this->ignorePaths)) {
            return null;
        }

        $event->request ??= [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
        ];
        $event->ip ??= $request->ip();
        $event->userAgent ??= $request->userAgent();
        $event->user ??= $this->getUser();

        return $event;
    }

    /**
     * @return array{id: string}|null
     */
    private function getUser(): ?array
    {
        if ($this->auth === null) {
            return null;
        }

        try {
            $guard = $this->auth->guard();

            // Only a user that is already loaded. Loading it here could query the database
            // while logging a database error.
            if (!$guard->hasUser()) {
                return null;
            }

            $id = $guard->id();
        } catch (Throwable) {
            return null;
        }

        return is_int($id) || is_string($id) ? ['id' => (string) $id] : null;
    }
}
