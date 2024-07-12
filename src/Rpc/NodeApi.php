<?php

namespace IMEdge\Node\Rpc;

use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Node\NodeRunner;
use IMEdge\RpcApi\ApiNamespace;
use Psr\Log\LoggerInterface;

#[ApiNamespace('node')]
class NodeApi
{
    public function __construct(
        protected NodeRunner $node,
        protected LoggerInterface $logger
    ) {
    }

    public function getIdentifierRequest(): NodeIdentifier
    {
        return $this->node->identifier;
    }

    public function getNameRequest(): string
    {
        return $this->node->getName();
    }

    public function getUuidRequest(): string
    {
        return $this->node->getUuid()->toString();
    }

    public function getAvailableMethodsRequest(): array
    {
        return [];
//        return $this->handler->getKnownMethods();
    }
}
