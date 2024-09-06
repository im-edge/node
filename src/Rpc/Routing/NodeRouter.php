<?php

namespace IMEdge\Node\Rpc\Routing;

use IMEdge\DistanceRouter\Route;
use IMEdge\DistanceRouter\RouteList;
use IMEdge\DistanceRouter\RoutingTable;
use IMEdge\JsonRpc\JsonRpcConnection;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class NodeRouter
{
    public const MAX_HOPS = 30;

    /** @readonly */
    public NodeList $directlyConnected;
    protected RoutingTable $routingTable;
    /** @var array<string, RouteList> */
    protected array $peerRoutes = [];

    public function __construct(
        public readonly Node $node,
        protected readonly LoggerInterface $logger,
    ) {
        $this->directlyConnected = new NodeList();
        $this->routingTable = new RoutingTable();
    }

    public function hasPeer(Node $peer): bool
    {
        return $this->directlyConnected->has($peer);
    }

    public function addPeer(Node $peer): void
    {
        if ($this->hasPeer($peer)) {
            throw new InvalidArgumentException('Cannot add peer twice: ' . $peer->name);
        }

        $this->directlyConnected->attach($peer);
        $this->logger->notice('NodeRunner, new directly connected peer: ' . $peer->name);
    }

    public function getConnectionFor(UuidInterface $target): ?JsonRpcConnection
    {
        $stringTarget = $target->toString();
        if ($connection = $this->directlyConnected->getOptional($target->toString())) {
            return $connection;
        }

        if ($route = $this->routingTable->active->getRouteTo($stringTarget)) {
            return $this->directlyConnected->getOptional($route->via);
        }

        return null;
    }

    public function getActiveRoutes(): RouteList
    {
        return $this->routingTable->active;
    }

    public function setPeerRoutes(Node $peer, RouteList $routes): void
    {
        if (!$this->hasPeer($peer)) {
            throw new InvalidArgumentException('Rejecting routing table for unknown peer ' . $peer->name);
        }

        $this->setPeerTable($peer->name, $routes);
    }

    public function removePeer(Node $peer): void
    {
        if (!$this->hasPeer($peer)) {
            return;
        }

        $this->removePeerByName($peer->name);
    }

    protected function setPeerTable(string $name, RouteList $routes): void
    {
        if (isset($this->peerRoutes[$name])) {
            $this->routingTable->applyDiff($this->peerRoutes[$name], $routes);
        } else {
            $this->routingTable->addCandidatesFromList($routes);
        }

        $this->peerRoutes[$name] = $routes;
    }

    public function removePeerByName(string $name): void
    {
        if (isset($this->peerRoutes[$name])) {
            foreach ($this->peerRoutes[$name]->routes as $route) {
                $this->routingTable->removeCandidate($route);
            }
            unset($this->peerRoutes[$name]);
        }
        if ($this->directlyConnected->getOptional($name)) {
            $this->directlyConnected->detach(new Node(Uuid::fromString($name), $name));
        }
    }
}
