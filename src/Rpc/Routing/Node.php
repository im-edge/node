<?php

namespace IMEdge\Node\Rpc\Routing;

use IMEdge\JsonRpc\JsonRpcConnection;
use JsonSerializable;
use Ramsey\Uuid\UuidInterface;
use stdClass;

class Node implements JsonSerializable
{
    public function __construct(
        public readonly UuidInterface $uuid,
        public readonly string $name,
        public readonly ?JsonRpcConnection $connection = null,
    ) {
    }

    public function jsonSerialize(): stdClass
    {
        return (object) [
            'state' => 'connected',
            // TODO: peerAddress is not correct
            'peerAddress'    => $this->name,
            'peerIdentifier' => $this->uuid->toString()
        ];
    }
}
