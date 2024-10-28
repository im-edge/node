<?php

namespace IMEdge\Node\Rpc\Api;

use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;
use Psr\Log\LoggerInterface;

#[ApiNamespace('logger')]
class LogApi
{
    public function __construct(
        protected LoggerInterface $logger,
        protected string $prefix = '',
    ) {
    }

    #[ApiMethod]
    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $this->prefix . $message, $context);
    }
}
