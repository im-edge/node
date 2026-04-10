<?php

namespace IMEdge\Node\Redis;

use Amp\Redis\RedisClient;
use Amp\Redis\RedisSubscriber;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Node\ApplicationContext;
use IMEdge\RedisTables\RedisTables;
use Psr\Log\LoggerInterface;

use function Amp\Redis\createRedisClient;
use function Amp\Redis\createRedisConnector;

class ImedgeRedis
{
    public static function getDefaultUri(): string
    {
        return 'unix://' . ApplicationContext::getRedisSocket();
    }

    public static function client(string $clientName, ?string $redisUri = null): RedisClient
    {
        $redisUri ??= self::getDefaultUri();

        return createRedisClient(
            $redisUri,
            self::connector($clientName, $redisUri)
        );
    }

    public static function subscriber(string $clientName, ?string $redisUri = null): RedisSubscriber
    {
        return new RedisSubscriber(self::connector($clientName, $redisUri));
    }

    public static function tables(
        NodeIdentifier $node,
        LoggerInterface $logger,
        string $clientName,
        ?string $redisUri = null
    ): RedisTables {
        return new RedisTables($node->uuid->toString(), self::client($clientName, $redisUri), $logger);
    }

    public static function connector(string $clientName, ?string $redisUri = null): NamedRedisConnector
    {
        $redisUri ??= self::getDefaultUri();

        return new NamedRedisConnector($clientName, createRedisConnector($redisUri));
    }
}
