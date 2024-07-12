<?php

namespace IMEdge\Node\Command;

use gipfl\Protocol\JsonRpc\JsonRpcConnection;

interface RpcCommandInterface
{
    public function rpc(): JsonRpcConnection;
}
