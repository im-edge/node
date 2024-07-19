<?php

namespace IMEdge\Node;

use Amp\Redis\RedisClient;
use IMEdge\RedisTables\RedisTables;
use Psr\Log\LoggerInterface;

use function Amp\Redis\createRedisClient;

class Services
{
    public function __construct(
        protected NodeRunner $dataNode,
        protected LoggerInterface $logger,
    ) {
    }

    public function getRedisSocket(): string
    {
        return $this->dataNode->getRedisSocket();
    }

    public function getRedisClient(string $clientName): RedisClient
    {
        // $this->logger->notice("Getting (new) Redis client for $clientName");
        $client = createRedisClient($this->getRedisSocket());
        $client->execute('CLIENT', 'SETNAME', $clientName);

        return $client;
    }

    public function getRedisTables(string $clientName): RedisTables
    {
        // $this->logger->notice("Getting (new) Redis table for $clientName");
        $client = $this->getRedisClient($clientName);
        $this->logger->notice("RedisTables ready for $clientName");
        return new RedisTables($this->dataNode->getUuid()->toString(), $client, $this->logger);
    }
}
