<?php

namespace IMEdge\Node\Network;

use IMEdge\JsonRpc\JsonRpcConnection;
use IMEdge\Node\Rpc\RpcPeerType;

interface ConnectionSubscriberInterface
{
    public function activateConnection(string $hexUuid, JsonRpcConnection $connection, RpcPeerType $peerType): void;

    public function deactivateConnection(string $hexUuid): void;
}
