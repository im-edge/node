<?php

namespace IMEdge\Node\Rpc\Routing;

use gipfl\Json\JsonSerialization;
use IMEdge\JsonRpc\JsonRpcConnection;
use RuntimeException;

class NodeList implements JsonSerialization
{
    /**
     * @var array<string, Node>
     */
    protected array $nodes = [];

    public function attach(Node $node): void
    {
        if ($this->has($node)) {
            throw new RuntimeException('Cannot register node twice: ' . $node->name);
        }

        $this->nodes[$node->uuid->toString()] = $node;
    }

    public function detach(Node $node): void
    {
        unset($this->nodes[$node->uuid->toString()]);
    }

    public function has(Node $node): bool
    {
        return isset($this->nodes[$node->uuid->toString()]);
    }

    public function getOptional(string $id): ?JsonRpcConnection
    {
        if (isset($this->nodes[$id])) {
            return $this->nodes[$id]->connection;
        }

        return null;
    }

    /**
     * @return Node[]
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    public static function fromSerialization($any)
    {
        // TODO: Implement fromSerialization() method.
    }

    public function jsonSerialize(): array
    {
        return $this->nodes;
    }
}
