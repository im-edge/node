<?php

namespace IMEdge\Node\Network;

use Exception;
use gipfl\Protocol\JsonRpc\Handler\NamespacedPacketHandler;
use gipfl\Protocol\JsonRpc\JsonRpcConnection;
use IMEdge\Node\NodeRunner;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;

use function array_key_first;

/**
 * @deprecated soon
 */
class DataNodeConnections
{
    /** @var array<string, array<string, JsonRpcConnection>> Indexed by remote Uuid (textual representation), peer address */
    protected array $connectedCandidates = [];

    /** @var array<string, JsonRpcConnection> Indexed by remote Uuid (textual representation) */
    protected array $activeConnections = [];

    /** @var array<string, string> Remote UUID, remote address */
    protected array $activeUuids = [];

    /** @var array<string, string> */
    protected array $peerUuids = [];

    public function __construct(
        protected readonly NodeRunner $dataNode,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function getOptionalConnection(string $hexUuid): ?JsonRpcConnection
    {
        return $this->activeConnections[$hexUuid] ?? null;
    }

    public function hasConnections(): bool
    {
        return !empty($this->activeConnections);
    }

    public function listActiveUuids(): array
    {
        return array_keys($this->activeConnections);
    }

    public function onConnectedPeer(
        ConnectionHandler $handler,
        JsonRpcConnection $connection,
        string $peerAddress
    ): void {
        // TODO: Find a better solution. Peers might take some time between being
        //       connected and being ready with a registered RPC datanode-namespace.
        //       Not an issue with normal connections, seems to be an issue with
        //       child processes (-> metric store)
        $this->logger->notice('New peer connected, attaching empty handler, getUuid in 1s');
        $rpcHandler = $connection->getHandler();
        if ($rpcHandler === null) {
            $rpcHandler = new NamespacedPacketHandler();
            $connection->setHandler($rpcHandler);
        } elseif (!$rpcHandler instanceof NamespacedPacketHandler) {
            throw new \RuntimeException('Unexpected packet handler: ' . get_class($rpcHandler));
        }

        Loop::addTimer(1, function () use ($connection, $peerAddress, $handler) {
            $connection->request('node.getUuid')->then(function ($hexUuid) use (
                $connection,
                $peerAddress,
                $handler,
            ) {
                $this->peerUuids[$peerAddress] = $hexUuid;
                $handler->setPeerIdentifier($peerAddress, $hexUuid);

                $this->logger->info(sprintf(
                    'Connected to %s via %s',
                    $hexUuid,
                    $peerAddress
                ));
                $this->connectedCandidates[$hexUuid][$peerAddress] = $connection;
                if (!isset($this->activeConnections[$hexUuid])) {
                    $this->activateSubscribers($connection, $hexUuid);
                    $this->activeConnections[$hexUuid] = $connection;
                    $this->activeUuids[$hexUuid] = $peerAddress;
                }
            }, function (Exception $e) use ($connection) {
                $this->logger->error('Failed to get UUID: ' . $e->getMessage());
                $connection?->close();
            });
        });
    }

    protected function activateSubscribers(JsonRpcConnection $connection, $hexUuid): void
    {
        $this->logger->notice('ACTIVATING SUBSCRIBERS FOR ' . $hexUuid);
        foreach ($this->dataNode->getFeatures()->getLoaded() as $feature) {
            $this->logger->notice(sprintf(
                ' - activating %s (%d connection subscribers)',
                $feature->name,
                count($feature->getConnectionSubscribers())
            ));
            foreach ($feature->getConnectionSubscribers() as $connectionSubscriber) {
                $connectionSubscriber->activateConnection($hexUuid, $connection);
            }
        }
    }

    protected function deactivateSubscribers($hexUuid): void
    {
        foreach ($this->dataNode->getFeatures()->getLoaded() as $feature) {
            foreach ($feature->getConnectionSubscribers() as $connectionSubscriber) {
                $connectionSubscriber->deactivateConnection($hexUuid);
            }
        }
    }

    public function onDisconnect(string $peerAddress): void
    {
        // We have no peer UUID, in case we haven't been able to retrieve one after connecting
        $hexUuid = $this->peerUuids[$peerAddress] ?? null;
        unset($this->peerUuids[$peerAddress]);

        if ($hexUuid) {
            // Remove this candidate
            unset($this->connectedCandidates[$hexUuid][$peerAddress]);
            if (empty($this->connectedCandidates[$hexUuid])) {
                unset($this->connectedCandidates[$hexUuid]);
            }

            // If active, remove
            if (isset($this->activeUuids[$hexUuid]) && $this->activeUuids[$hexUuid] === $peerAddress) {
                unset($this->activeConnections[$hexUuid]);
                unset($this->activeUuids[$hexUuid]);

                // pick next candidate, if available
                if (isset($this->connectedCandidates[$hexUuid])) {
                    $peerAddress = array_key_first($this->connectedCandidates[$hexUuid]);
                    $this->activeConnections[$hexUuid] = $this->connectedCandidates[$hexUuid][$peerAddress];
                    $this->activeUuids[$hexUuid] = $peerAddress;
                }
            }

            $this->deactivateSubscribers($hexUuid);
        }
    }
}
