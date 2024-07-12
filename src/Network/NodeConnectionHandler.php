<?php

namespace IMEdge\Node\Network;

use Exception;
use IMEdge\JsonRpc\JsonRpcConnection;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Revolt\EventLoop;

/**
 * WIP?!
 *
 * JsonRpc ConnectionHandler
 *
 * Deals with connections to other data nodes. Is responsible for reconnects
 */
class NodeConnectionHandler
{
    /** @var array<string, ConnectionInformation> Connection information, indexed by peer address */
    protected array $connections = [];

    /** @var array<string, \stdClass> */
    protected array $configuredConnections = [];

    /** @var array<string, Deferred> Pending connections, indexed by peer address */
    protected array $pendingConnections = [];

    /** @var array<string, TimerInterface> Retry timer, indexed by peer address */
    protected array $failingConnections = [];

    /** @var array<string, JsonRpcConnection> Established connections, indexed by peer address */
    protected array $establishedConnections = [];

    public function __construct(
        public readonly DataNodeConnections $dataNodeConnections,
        public readonly LoggerInterface $logger,
    ) {
    }

    public function setConfiguredConnections(array $connections): void
    {
        $removed = array_diff_key($this->configuredConnections, $connections);
        foreach ($removed as $peerAddress => $connectionConfiguration) {
            Loop::futureTick(function () use ($peerAddress) {
                $this->disconnect($peerAddress);
            });
        }
        $this->configuredConnections = $connections;
        foreach ($connections as $peerAddress => $connectionConfiguration) {
            Loop::futureTick(function () use ($peerAddress) {
                $this->connect($peerAddress)->catch(function (Exception $e) {
                    $this->logger->notice('WHA? ' . $e->getMessage());
                });
            });
        }
    }

    public function setPeerIdentifier(string $peerAddress, string $peerIdentifier): void
    {
        $this->connections[$peerAddress]->peerIdentifier = $peerIdentifier;
    }

    public function connect(string $peerAddress): PromiseInterface
    {
        if (isset($this->establishedConnections[$peerAddress])) {
            $this->logger->notice("$peerAddress: already established");
            return LegacyDeferredResult::success($this->establishedConnections[$peerAddress]);
        }

        if (isset($this->pendingConnections[$peerAddress])) {
            $this->logger->notice("$peerAddress: already pending");
            return $this->pendingConnections[$peerAddress]->promise();
        }

        if (isset($this->failingConnections[$peerAddress])) {
            $this->logger->notice("$peerAddress: already failing, trying immediately");
            Loop::cancelTimer($this->failingConnections[$peerAddress]);
            unset($this->failingConnections[$peerAddress]);
            return $this->connect($peerAddress);
        }

        if (! isset($this->connections[$peerAddress])) {
            $this->connections[$peerAddress] = new ConnectionInformation($peerAddress);
            $this->logger->debug('Connecting to ' . $peerAddress);
        }

        return $this->establishConnection($peerAddress);
    }

    public function establishConnection(string $peerAddress): PromiseInterface
    {
        $rpc = new RemoteClient($peerAddress);
        $deferred = new Deferred();
        $this->pendingConnections[$peerAddress] = $deferred;
        $rpc->connect()->then(function () use ($rpc, $peerAddress, $deferred) {
            $this->connectionEstablished($rpc, $peerAddress);
            $deferred->resolve(true);
        }, function (Exception $e) use ($peerAddress, $deferred) {
            $this->scheduleConnectAfterFailure($e, $peerAddress);
            $deferred->reject($e);
        })->catch(function (Exception $e) {
            $this->logger->notice('WTF, late catch? ' . $e->getMessage());
        });

        return $deferred->promise();
    }

    protected function connectionEstablished(RemoteClient $rpc, string $peerAddress): void
    {
        $connection = $rpc->connection;
        $this->establishedConnections[$peerAddress] = $connection;
        unset($this->pendingConnections[$peerAddress]);
        $this->connections[$peerAddress]->state = ConnectionState::CONNECTED;
        $this->connections[$peerAddress]->errorMessage = null;
        $this->dataNodeConnections->onConnectedPeer($this, $connection, $peerAddress);
        $connection->on('close', function () use ($peerAddress) {
            $this->closePeerConnection($peerAddress);
        });
    }

    protected function scheduleConnectAfterFailure(Exception $e, string $peerAddress): void
    {
        if (!isset($this->connections[$peerAddress])) {
            return;
        }
        unset($this->pendingConnections[$peerAddress]);
        $this->connections[$peerAddress]->state = ConnectionState::FAILING;
        $interval = 5;
        $logMessage = sprintf(
            'Connection attempt to %s failed, will retry every %ds: %s',
            $peerAddress,
            $interval,
            $e->getMessage()
        );
        if ($this->connections[$peerAddress]->errorMessage !== $logMessage) {
            $this->connections[$peerAddress]->errorMessage = $logMessage;
            $this->logger->error($logMessage);
        }
        $this->failingConnections[$peerAddress] = Loop::addTimer($interval, function () use ($peerAddress) {
            unset($this->failingConnections[$peerAddress]);
            $this->connect($peerAddress)->catch(function (Exception $e) {
                // No need to log this
                // $this->logger->notice('Reconnection error: ' . $e->getMessage());
            });
        });
    }

    protected function closePeerConnection(string $peerAddress): void
    {
        $this->dataNodeConnections->onDisconnect($peerAddress);
        unset($this->establishedConnections[$peerAddress]);
        if (isset($this->connections[$peerAddress])) {
            $this->connections[$peerAddress]->state = ConnectionState::FAILING;
            $this->logger->notice("Connection to $peerAddress has been closed, will reconnect");
            $this->connect($peerAddress);
        } else {
            $this->logger->notice("Connection to $peerAddress has been closed");
        }
    }

    public function registerConnected(JsonRpcConnection $connection, string $peerAddress): void
    {
        $this->establishedConnections[$peerAddress] = $connection;
        $this->connections[$peerAddress] = new ConnectionInformation($peerAddress, null, ConnectionState::CONNECTED);
    }

    public function removeConnected(string $peerAddress): void
    {
        unset($this->establishedConnections[$peerAddress]);
        unset($this->connections[$peerAddress]);
    }

    public function getConnections(): array
    {
        return array_values($this->connections);
    }

    public function disconnect(string $peerAddress): void
    {
        $this->logger->notice('Disconnecting from ' . $peerAddress);
        if (isset($this->connections[$peerAddress])) {
            unset($this->connections[$peerAddress]);
        }
        if (isset($this->establishedConnections[$peerAddress])) {
            $connection = $this->establishedConnections[$peerAddress];
            unset($this->establishedConnections[$peerAddress]);
            EventLoop::queue($connection->close(...));
        }
        if (isset($this->pendingConnections[$peerAddress])) {
            EventLoop::queue(function () use ($peerAddress) {
                $this->pendingConnections[$peerAddress]->reject(new Exception('Connection closed'));
            });
        }
        if (isset($this->failingConnections[$peerAddress])) {
            Loop::cancelTimer($this->failingConnections[$peerAddress]);
            unset($this->failingConnections[$peerAddress]);
        }
    }
}
