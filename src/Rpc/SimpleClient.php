<?php

namespace IMEdge\Node\Rpc;

use IMEdge\JsonRpc\JsonRpcConnection;
use IMEdge\Protocol\NetString\NetStringConnection;
use Psr\Log\LoggerInterface;
use stdClass;

use function Amp\Socket\connect;

class SimpleClient
{
    protected JsonRpcConnection $jsonRpc;
    protected ?string $targetNode = null;

    public function __construct(string $socketPath, LoggerInterface $logger)
    {
        $socket = connect('unix://' . $socketPath);
        $netString = new NetStringConnection($socket, $socket);
        $this->jsonRpc = new JsonRpcConnection($netString, $netString, null, $logger);
    }

    public function setTarget(?string $node): void
    {
        $this->targetNode = $node;
    }

    public function request(string $method, array|stdClass $params = null)
    {
        return $this->jsonRpc->request($method, $params, $this->getExtraProperties());
    }

    protected function getExtraProperties(): ?stdClass
    {
        if ($this->targetNode) {
            return (object) ['target' => $this->targetNode];
        }

        return null;
    }
}
