<?php

namespace IMEdge\Node\Rpc;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate as AmpCertificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\PendingAcceptError;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\ServerTlsContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use Amp\Socket\TlsException;
use Exception;
use IMEdge\Async\Retry;
use IMEdge\CertificateStore\CertificateHelper;
use IMEdge\CertificateStore\ClientStore\ClientSslStoreDirectory;
use IMEdge\CertificateStore\ClientStore\ClientSslStoreInterface;
use IMEdge\CertificateStore\Generator\KeyGenerator;
use IMEdge\CertificateStore\TrustStore\TrustStoreDirectory;
use IMEdge\DistanceRouter\Route;
use IMEdge\DistanceRouter\RouteList;
use IMEdge\JsonRpc\JsonRpcConnection;
use IMEdge\Node\Feature;
use IMEdge\Node\Features;
use IMEdge\Node\Network\ConnectionInformation;
use IMEdge\Node\Network\ConnectionState;
use IMEdge\Node\NodeRunner;
use IMEdge\Node\Rpc\Api\NodeApi;
use IMEdge\Node\Rpc\Routing\Node;
use IMEdge\Protocol\NetString\NetStringConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Revolt\EventLoop;
use Sop\CryptoEncoding\PEM;
use Sop\X509\Certificate\Certificate;

use function Amp\Socket\connect;
use function Amp\Socket\listen;

// TODO? RpcConnectionSetup?
class RpcConnections
{
    public ClientSslStoreInterface $sslStore;
    protected string $certName;
    public TrustStoreDirectory $trustStore;

    /** @var ResourceServerSocket[] */
    protected array $listeners = [];
    /** @var array<string, \stdClass> */
    protected array $configuredListeners = [];

    protected DeferredCancellation $stopper;

    /** @var array<string, \stdClass> */
    protected array $configured = [];
    /** @var array<string, DeferredFuture> Pending connections, indexed by peer address */
    protected array $pending = [];

    /** @var array<string, string> Retry timer, indexed by peer address */
    protected array $failing = [];

    /** @var array<string, JsonRpcConnection> Established connections, indexed by peer address */
    protected array $established = [];

    /** @var array<string, ConnectionInformation> Connection information, indexed by peer address */
    protected array $connections = [];

    public function __construct(
        protected readonly NodeRunner $node,
        protected readonly Features $features,
        protected readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $basedir = $this->node->getConfigDir();
        $this->sslStore = new ClientSslStoreDirectory("$basedir/ssl");
        $this->trustStore = new TrustStoreDirectory("$basedir/trust");
        $this->certName = $node->getUuid()->toString();
        $this->stopper = new DeferredCancellation();
    }

    public function setConfiguredConnections(array $connections): void
    {
        $removed = array_diff_key($this->configured, $connections);
        foreach ($removed as $peerAddress => $connectionConfiguration) {
            EventLoop::queue(function () use ($peerAddress) {
                $this->disconnect($peerAddress);
            });
        }
        $this->configured = $connections;
        foreach ($connections as $peerAddress => $connectionConfiguration) {
            EventLoop::queue(function () use ($peerAddress) {
                try {
                    $this->connect($peerAddress); // TODO: from configuration
                } catch (Exception $e) {
                    $this->logger->notice("Peer connection failed (where it shouldn't): " . $e->getMessage());
                }
            });
        }
    }

    public function setConfiguredListeners(array $configured): void
    {
        $removed = array_diff_key($this->configuredListeners, $configured);
        foreach ($removed as $socket => $connectionConfiguration) {
            EventLoop::queue(function () use ($socket) {
                $this->stopListener($socket);
            });
        }
        $this->configuredListeners = $configured;
        foreach ($configured as $socket => $connectionConfiguration) {
            EventLoop::queue(function () use ($socket) {
                try {
                    $bind = $this->listen($socket);
                    try {
                        foreach ($bind as $connection) {
                            // TODO: Move this loop elsewhere
                            $this->logger->notice('Got a connection on tcp://' . $socket);
                        }
                        // Socket stopped.
                    } catch (\Throwable $e) {
                        $this->logger->notice("Socket tcp://$socket failed: " . $e->getMessage());
                    }
                } catch (Exception $e) {
                    $this->logger->notice("Listening failed (where it shouldn't): " . $e->getMessage());
                }
            });
        }
    }

    public function stop(): void
    {
        $this->stopper->cancel();
    }

    public function listListeners(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * @param string $address e.g. tcp://127.0.0.1:5661
     * @throws \Amp\Socket\SocketException
     * @return \Generator<JsonRpcConnection>
     */
    public function listen(string $address): \Generator
    {
        $server = listen($address, $this->prepareBindContext());
        $this->listeners[$address] = $server;
        while (true) {
            try {
                $socket = $server->accept($this->stopper->getCancellation());
            } catch (PendingAcceptError $e) {
                $this->logger->error('Failed to accept socket connection: ' . $e->getMessage());
                continue;
            }
            if ($socket === null) {
                $this->logger->notice("Stopped listening on $address");
                return;
            }

            $this->logger->notice(sprintf(
                'Got a new connection from %s',
                $socket->getRemoteAddress()->toString()
            ));
            if ($rpc = $this->connectionEstablished($socket, ConnectionDirection::INCOMING)) {
                yield $rpc;
            }
        }
    }

    public function stopListener(string $address): bool
    {
        if (isset($this->listeners[$address])) {
            $socket = $this->listeners[$address];
            $socket->close();
            unset($this->listeners[$address]);
            return true;
        }

        return false;
    }

    public function close(JsonRpcConnection $connection): void
    {
        unset($this->connections[spl_object_id($connection)]);
        $connection->close();
    }

    protected function connectionEstablished(Socket $socket, ConnectionDirection $direction): ?JsonRpcConnection
    {
        $remoteAddress = $socket->getRemoteAddress();
        $peerType = RpcPeerType::ANONYMOUS;
        if ($direction === ConnectionDirection::INCOMING) {
            $this->logger->debug("Got a new connection from $remoteAddress");
        } else {
            $this->logger->debug("A new connetion to $remoteAddress has been established");
        }

        try {
            $socket->setupTls();
        } catch (TlsException $e) {
            $this->logger->error(sprintf(
                'TLS setup for %s failed: %s',
                $remoteAddress->toString(),
                $e->getMessage()
            ));
            $socket->close();

            return null;
        }

        try {
            $remoteName = null;
            foreach ($socket->getTlsInfo()->getPeerCertificates() as $certificate) {
                $cert = Certificate::fromPEM(PEM::fromString($certificate->toPem()));
                if ($this->trustStore->isValid($cert)) { // useless, we no longer allow unsigned certs
                    $peerType = RpcPeerType::PEER;
                }
                // TODO:
                // Currently, listeners are being controlled. We should provide a configurable list of
                // control nodes, or alternatively use a specific custom property in the certificate
                if ($direction === ConnectionDirection::INCOMING) {
                    $peerType = RpcPeerType::CONTROL;
                }
                if ($remoteName === null) {
                    $remoteName = CertificateHelper::getSubjectName($cert);
                }
            }
        } catch (SocketException $e) {
            // Message is:
            // Peer certificates not captured; use ClientTlsContext::withPeerCapturing() to capture peer certificates
            $this->logger->error(sprintf(
                'Failed to retrieve peer certificates from %s, closing socket in 5 seconds: %s',
                $remoteAddress->toString(),
                $e->getMessage()
            ));
            EventLoop::delay(5, $socket->close(...));
            $this->connections[$remoteAddress->toString()] = new ConnectionInformation(
                $remoteAddress->toString(),
                null,
                ConnectionState::FAILING,
                $e->getMessage()
            );

            return null;
            // TODO: Normally we should not fall through?!
        }

        $netString = new NetStringConnection($socket, $socket);

        $handler = new ApiRunner($this->certName, $this->node->nodeRouter, $this->logger);
        if (true || $peerType === RpcPeerType::CONTROL) {
            $handler->addApi(new NodeApi(
                $this->node,
                $handler,
                $this->logger
            ));
        }

        foreach ($this->features->getLoaded() as $feature) {
            foreach ($feature->getRegisteredRpcApis() as $featureApi) {
                $handler->addApi($featureApi);
            }
        }
        // What about $this->requestHandler?
        $jsonRpc = new JsonRpcConnection($netString, $netString, $handler);

        $idx = spl_object_id($jsonRpc);
        $this->established[$idx] = $jsonRpc;
        $this->connections[$remoteAddress->toString()] = new ConnectionInformation(
            $remoteAddress->toString(),
            $remoteName,
            ConnectionState::CONNECTED
        );
        $socket->onClose(function () use ($idx, $remoteAddress, $remoteName) {
            unset($this->established[$idx]);
            unset($this->connections[$remoteAddress->toString()]);
            $this->node->nodeRouter->removePeerByName($remoteName);
            $this->tellFeaturesAboutLostConnection($remoteName);
            // Change information, if configured - otherwise forget ist
            $this->logger->notice(sprintf('Connection with %s has been closed', $remoteAddress->toString()));
        });
        // Not yet: $routes = $jsonRpc->request('node.getActiveRoutes');
        // NodeList::fromSerialization ? Do we need NodeList?
        $this->updateRoutes($jsonRpc, $remoteName);
        $this->tellFeaturesAboutConnection($jsonRpc, $remoteName, $peerType);

        return $jsonRpc;
    }

    protected function updateRoutes(JsonRpcConnection $connection, string $peerIdentifier): void
    {
        $remoteNode = new Node(Uuid::fromString($peerIdentifier), $peerIdentifier, $connection);
        $this->node->nodeRouter->addPeer($remoteNode);
        $remoteConnected = $connection->request('node.getDirectlyConnectedNodes');
        $remoteRouteList = new RouteList();
        $myself = $this->node->identifier->uuid->toString();
        foreach ($remoteConnected as $nodeIdentifier => $knownNode) {
            if ($nodeIdentifier === $myself) {
                continue;
            }

            $remoteRouteList->addRoute(new Route($nodeIdentifier, $remoteNode->uuid->toString(), 1));
        }

        // TODO: Add and increment remote routes
        $this->node->nodeRouter->setPeerRoutes($remoteNode, $remoteRouteList);
    }

    public function tellFeatureAboutConnection(
        Feature $feature,
        JsonRpcConnection $connection,
        string $peerIdentifier,
        RpcPeerType $peerType,
    ): void {
        $this->logger->notice(sprintf(
            ' - activating %s (%d connection subscribers)',
            $feature->name,
            count($feature->getConnectionSubscribers())
        ));
        foreach ($feature->getConnectionSubscribers() as $connectionSubscriber) {
            $connectionSubscriber->activateConnection($peerIdentifier, $connection, $peerType);
        }
    }

    protected function tellFeaturesAboutConnection(
        JsonRpcConnection $connection,
        string $peerIdentifier,
        RpcPeerType $peerType
    ): void
    {
        foreach ($this->features->getLoaded() as $feature) {
            $this->tellFeatureAboutConnection($feature, $connection, $peerIdentifier, $peerType);
        }
    }

    public function tellFeatureAboutLostConnection(
        Feature $feature,
        string $peerIdentifier
    ): void {
        $this->logger->notice(sprintf(
            ' - deactivating %s (%d connection subscribers)',
            $feature->name,
            count($feature->getConnectionSubscribers())
        ));
        foreach ($feature->getConnectionSubscribers() as $connectionSubscriber) {
            $connectionSubscriber->deactivateConnection($peerIdentifier);
        }
    }

    protected function tellFeaturesAboutLostConnection(
        string $peerIdentifier
    ): void
    {
        foreach ($this->features->getLoaded() as $feature) {
            $this->tellFeatureAboutLostConnection($feature, $peerIdentifier);
        }
    }

    /**
     * @param string $peerAddress e.g. 192.0.2.10:5661
     * @param string|null $fingerprint
     * @throws CancelledException|ConnectException
     */
    public function connect(string $peerAddress, ?string $fingerprint = null): JsonRpcConnection
    {
        if (isset($this->established[$peerAddress])) {
            $this->logger->notice("$peerAddress: already established");
            return $this->established[$peerAddress];
        }
        if (isset($this->pending[$peerAddress])) {
            $this->logger->notice("$peerAddress: already pending");
            return $this->pending[$peerAddress]->getFuture()->await();
        }
        if (isset($this->failing[$peerAddress])) {
            $this->logger->notice("$peerAddress: already failing, retrying immediately");
            EventLoop::cancel($this->failing[$peerAddress]);
            unset($this->failing[$peerAddress]);
            return $this->connect($peerAddress);
        }
        if (! isset($this->connections[$peerAddress])) {
            $this->connections[$peerAddress] = new ConnectionInformation($peerAddress);
            $this->logger->debug('Connecting to ' . $peerAddress);
        }

        $socket = connect(
            "tcp://$peerAddress",
            $this->prepareClientContext($fingerprint),
            $this->stopper->getCancellation()
        );
        $socket->onClose(function () use ($peerAddress, $fingerprint) {
            if (isset($this->configured[$peerAddress])) {
                $this->logger->notice("Reconnecting to $peerAddress in 5s");
                Retry::forever(fn () => $this->connect($peerAddress, $fingerprint), "Reconnecting to $peerAddress", 30, 5, 30, $this->logger);
            }
        });

        return $this->connectionEstablished($socket, ConnectionDirection::OUTGOING)
            ?? throw new Exception('Failed to connect');
    }

    public function disconnect(string $peerAddress): void
    {
        $this->logger->notice('Disconnecting from ' . $peerAddress);
        if (isset($this->connections[$peerAddress])) {
            unset($this->connections[$peerAddress]);
        }
        if (isset($this->established[$peerAddress])) {
            $connection = $this->established[$peerAddress];
            unset($this->established[$peerAddress]);
            EventLoop::queue($connection->close(...));
        }
        if (isset($this->pendingConnections[$peerAddress])) {
            $pending = $this->pending[$peerAddress];
            unset($this->pending[$peerAddress]);
            EventLoop::queue(function () use ($pending) {
                $pending->error(new Exception('Connection closed'));
            });
        }
        if (isset($this->failing[$peerAddress])) {
            EventLoop::cancel($this->failing[$peerAddress]);
            unset($this->failing[$peerAddress]);
        }
    }

    public function getConnections(): array
    {
        return array_values($this->connections);
    }

    protected function prepareBindContext(): BindContext
    {
        $certificate = $this->getMyConnectionCertificate();
        if (Certificate::fromPEM(PEM::fromFile($certificate->getCertFile()))->isSelfIssued()) {
            throw new Exception('Cannot listen with a self-signed certificate');
        }

        $tlsContext = (new ServerTlsContext())
            ->withCaPath($this->trustStore->getCaPath())
            ->withPeerCapturing()
            ->withDefaultCertificate($certificate)
            ->withPeerVerification()
            ->withoutPeerNameVerification()
            ;

        return (new BindContext())->withTlsContext($tlsContext);
    }

    protected function prepareClientContext(?string $fingerprint = null): ConnectContext
    {
        $certificate = $this->getMyConnectionCertificate();
        if (Certificate::fromPEM(PEM::fromFile($certificate->getCertFile()))->isSelfIssued()) {
            throw new Exception('Cannot connect with a self-signed certificate');
        }
        $tlsContext = (new ClientTlsContext())
            ->withCaPath($this->trustStore->getCaPath())
            ->withPeerCapturing()
            ->withCertificate($certificate)
            ->withoutPeerNameVerification()
        ;

        if ($fingerprint) {
            $tlsContext = $tlsContext->withPeerFingerprint($fingerprint);
        }

        return (new ConnectContext())->withTlsContext($tlsContext);
    }

    protected function getMyFingerprint(): string
    {
        return CertificateHelper::fingerprint($this->getMyCertificate());
    }

    protected function getMyCertificate(): ?Certificate
    {
        $certName = $this->certName;
        if (! $this->sslStore->hasCertificate($certName)) {
            $private = KeyGenerator::generate();
            $certificate = CertificateHelper::createTemporarySelfSigned($certName, $private);
            $this->sslStore->store($certificate, $private);
        }

        return $this->sslStore->readCertificate($certName);
    }

    protected function getMyConnectionCertificate(): AmpCertificate
    {
        $ssl = $this->sslStore;
        $certName = $this->certName;
        if (! $ssl->hasCertificate($certName)) {
            $private = KeyGenerator::generate();
            $certificate = CertificateHelper::createTemporarySelfSigned($certName, $private);
            $ssl->store($certificate, $private);
        }

        return new AmpCertificate($ssl->getCertificatePath($certName), $ssl->getPrivateKeyPath($certName));
    }
}
