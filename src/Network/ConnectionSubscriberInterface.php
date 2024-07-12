<?php

namespace IMEdge\Node\Network;

use IMEdge\JsonRpc\JsonRpcConnection;

interface ConnectionSubscriberInterface
{
    public function activateConnection(string $hexUuid, JsonRpcConnection $connection): void;

    public function deactivateConnection(string $hexUuid): void;
}
