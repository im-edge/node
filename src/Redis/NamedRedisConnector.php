<?php

namespace IMEdge\Node\Redis;

use Amp\Cancellation;
use Amp\Redis\Connection\RedisConnection;
use Amp\Redis\Connection\RedisConnector;
use Amp\Redis\RedisException;

class NamedRedisConnector implements RedisConnector
{
    public function __construct(
        public readonly string $clientName,
        protected RedisConnector $connector
    ) {
    }

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        $connection = $this->connector->connect($cancellation);

        $connection->send('CLIENT', 'SETNAME', $this->clientName);

        if (!($connection->receive()?->unwrap())) {
            throw new RedisException('Failed to select database: ' . $connection->getName());
        }

        return $connection;
    }
}
