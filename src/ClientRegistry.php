<?php

declare(strict_types=1);

namespace XInfra\Laravel;

use XInfra\Client;

/**
 * The clients of the resolved xInfra log channels, so they can be flushed from anywhere.
 */
final class ClientRegistry
{
    /** @var list<Client> */
    private array $clients = [];

    public function register(Client $client): void
    {
        $this->clients[] = $client;
    }

    /**
     * @return list<Client>
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    public function flush(): void
    {
        foreach ($this->clients as $client) {
            $client->flush();
        }
    }
}
