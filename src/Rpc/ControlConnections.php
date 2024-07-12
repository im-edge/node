<?php

namespace IMEdge\Node\Rpc;

use Amp\DeferredCancellation;
use Amp\Socket\PendingAcceptError;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use Amp\Socket\UnixAddress;
use IMEdge\JsonRpc\JsonRpcConnection;
use IMEdge\JsonRpc\RequestHandler;
use IMEdge\Node\NodeRunner;
use IMEdge\Node\Rpc\UnixSocket\UnixSocketInspection;
use IMEdge\Protocol\NetString\NetStringConnection;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

use function Amp\Socket\listen;

class ControlConnections
{
    /** @var JsonRpcConnection[] */
    protected array $connections = [];
    protected UnixAddress $socketAddress;
    protected ?ResourceServerSocket $server = null;
    protected ?DeferredCancellation $stopper = null;

    public function __construct(
        protected readonly NodeRunner $node,
        protected readonly ?RequestHandler $requestHandler = null,
        protected readonly ?LoggerInterface $logger = null,
    ) {
        $this->stopper = new DeferredCancellation();
    }

    public function stop(): void
    {
        $this->server?->close();
        $this->stopper?->cancel();
    }

    /**
     * @param string $address e.g. tcp://127.0.0.1:5661
     * @throws \Amp\Socket\SocketException
     */
    public function bind(string $address): void
    {
        $this->socketAddress = new UnixAddress($address);
        $this->removeOrphanedSocketFile($this->socketAddress->toString());
        $old = umask(0000);
        $this->server = listen($this->socketAddress);
        $this->logger->notice("Listening for control connections on $address");
        umask($old);
        EventLoop::queue($this->keepRunning(...));
    }

    protected function keepRunning(): void
    {
        while (true) {
            try {
                $socket = $this->server->accept($this->stopper->getCancellation());
            } catch (PendingAcceptError $e) {
                $this->logger?->error('Failed to accept socket connection: ' . $e->getMessage());
                continue;
            }
            $this->connectionEstablished($socket);
        }
    }

    // TODO: How should this work? We need to provide connection IDs in a connected-list, and close them
    public function close(JsonRpcConnection $connection): void
    {
        unset($this->connections[spl_object_id($connection)]);
        $connection->close();
    }

    protected function connectionEstablished(Socket $socket): void
    {
        $remoteAddress = $socket->getRemoteAddress();
        $peerType = RpcPeerType::ANONYMOUS;
        // TODO: Unix socket auth check
        $peer = UnixSocketInspection::getPeer($socket->getResource());
        // $this->logger?->debug(sprintf('Got a new connection from %s', $peer->username));
        // TODO: log metrics, connections by user

        $netString = new NetStringConnection($socket, $socket);
        $jsonRpc = new JsonRpcConnection($netString, $netString, $this->requestHandler, $this->logger);
        $idx = spl_object_id($jsonRpc);
        $this->connections[$idx] = $jsonRpc;
        $socket->onClose(function () use ($idx) {
            unset($this->connections[$idx]);
            // $this->logger->notice('Connection closed');
        });
    }

    protected function removeOrphanedSocketFile($path): void
    {
        if (file_exists($path)) {
            $this->logger?->notice("Removing orphaned socket path: $path");
            unlink($path);
        }
    }
}
