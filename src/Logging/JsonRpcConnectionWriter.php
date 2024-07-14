<?php

namespace IMEdge\Node\Logging;

use gipfl\Log\LogWriterWithContext;
use IMEdge\JsonRpc\JsonRpcConnection;

use function iconv;
use function microtime;

class JsonRpcConnectionWriter implements LogWriterWithContext
{
    protected const DEFAULT_RPC_METHOD = 'logger.log';
    protected string $method = self::DEFAULT_RPC_METHOD;

    public function __construct(
        protected JsonRpcConnection $connection,
        protected array $defaultContext = []
    ) {
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    public function write($level, $message, $context = [])
    {
        $message = iconv('UTF-8', 'UTF-8//IGNORE', $message);
        $this->connection->notification($this->method, [
            'level'     => $level,
            // 'timestamp' => microtime(true),
            'message'   => $message,
            'context'   => $this->defaultContext + $context,
        ]);
    }
}
