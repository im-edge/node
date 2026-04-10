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
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketException;
use DateTimeImmutable;
use Exception;
use IMEdge\Async\Retry;
use IMEdge\CertificateStore\CaStore\CaStoreDirectory;
use IMEdge\CertificateStore\CertificateHelper;
use IMEdge\CertificateStore\CertificationAuthority;
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
use IMEdge\Node\Rpc\Api\CaApi;
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

    /**
     * Configured connections
     *
     * Read from config, tweaked at runtime. An explicit disconnect () might remove a configured
     * connection
     *
     * @var array<string, \stdClass>
     */
    protected array $configured = [];

    /** @var array<string, DeferredFuture> Pending connections, indexed by peer address */
    protected array $pending = [];

    /** @var array<string, string> Retry timer, indexed by peer address */
    protected array $failing = [];

    /** @var array<string, JsonRpcConnection> Established connections, indexed by peer address */
    protected array $established = [];

    /** @var array  */
    // Hmmmmmm....
    // protected array $jsonRpcConnections = [];

    /** @var array<string, ConnectionInformation> Connection information, indexed by peer address */
    protected array $connections = [];

    protected ?CertificationAuthority $ca = null;

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
            $this->scheduleConnection($peerAddress);
        }
    }

    protected function scheduleConnection(string $peerAddress): void
    {
        EventLoop::queue(function () use ($peerAddress) {
            try {
                // TODO: keep connecting?
                $this->connect($peerAddress); // TODO: from configuration
            } catch (Exception $e) {
                $this->logger->error("Peer connection failed: " . $e->getMessage());
            }
        });
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
            $this->launchListener($socket, $connectionConfiguration);
        }
    }

    protected function launchListener(string $socket, $connectionConfiguration): void
    {
        EventLoop::queue(function () use ($socket) {
            try {
                $bind = $this->listen($socket);
                try {
                    foreach ($bind as $connection) {
                        /** @var JsonRpcConnection $connection */
                        // TODO: Move this loop elsewhere. We must loop, as it is a generator
                        $this->logger->notice('Got a connection on tcp://' . $socket);
                    }
                    // Socket stopped.
                } catch (\Throwable $e) {
                    $this->logger->notice("Socket tcp://$socket failed: " . $e->getMessage());
                }
            } catch (Exception $e) {
                $this->logger->error("Failed to listen on $socket, will not be tried again: " . $e->getMessage());
            }
        });
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

            $this->logger->notice(sprintf('Got a new connection from %s', $socket->getRemoteAddress()->toString()));
            if ($rpc = $this->onConnectionEstablished($socket, ConnectionDirection::INCOMING)) {
                yield $rpc;
            }
        }
    }

    /**
     * TODO: Duplicated Code from CaApi
     */
    protected function ca(): CertificationAuthority
    {
        return $this->ca ??= new CertificationAuthority(
            CaApi::DEFAULT_CA_NAME,
            new CaStoreDirectory($this->node->getConfigDir() . '/' . CaApi::DEFAULT_CA_DIR)
        );
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

    /**
     * @deprecated not deprecated, but broken
     */
    public function close(JsonRpcConnection $connection): void
    {
        // This doesn't work this way, we need the peer address
        unset($this->connections[spl_object_id($connection)]);
        $connection->close();
    }

    protected function setupTls(Socket $socket, ConnectionDirection $direction): bool
    {
        $remoteAddress = $socket->getRemoteAddress();

        try {
            $socket->setupTls();
        } catch (SocketException $e) {
            // Hint: mostly TlsException
            $this->logger->error(sprintf(
                'TLS setup for %s failed: %s',
                $remoteAddress->toString(),
                $e->getMessage()
            ));
            if ($direction === ConnectionDirection::OUTGOING) {
                $key = $socket->getRemoteAddress()->toString();
                $this->failing[$key] = EventLoop::delay(5, function () use ($key) {
                    unset($this->failing[$key]);
                    // $this->connect($key);
                });
            }

            $socket->close();

            return false;
        }

        return true;
    }

    protected function checkCertificatesAndGetRemoteName(Socket $socket, ConnectionDirection $direction): ?string
    {
        $remoteAddress = $socket->getRemoteAddress();

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
            $this->connections[$remoteAddress->toString()] = new ConnectionInformation(
                $remoteAddress->toString(),
                $remoteName,
                ConnectionState::CONNECTED
            );

            return $remoteName;
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
    }

    protected function initializeJsonRpc(Socket $socket, ConnectionDirection $direction): ?JsonRpcConnection
    {
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
                try {
                    $handler->addApi($featureApi);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        'Failed to register API for feature %s: %s',
                        $feature->name,
                        $e->getMessage()
                    ));
                }
            }
        }
        // What about $this->requestHandler?

        return new JsonRpcConnection($netString, $netString, $handler, $this->logger);
    }

    protected function onConnectionEstablished(Socket $socket, ConnectionDirection $direction): ?JsonRpcConnection
    {
        $remoteAddress = $socket->getRemoteAddress();
        $peerType = RpcPeerType::ANONYMOUS;
        if ($direction === ConnectionDirection::INCOMING) {
            $this->logger->debug("Got a new incoming connection from $remoteAddress");
        } else {
            $this->logger->debug("A new outgoing connection to $remoteAddress has been established");
        }
        if (!$this->setupTls($socket, $direction)) {
            return null;
        }
        if (null === ($remoteName = $this->checkCertificatesAndGetRemoteName($socket, $direction))) {
            return null;
        }
        $jsonRpc = $this->initializeJsonRpc($socket, $direction);

        $rpcIdx = spl_object_id($jsonRpc); // Has formerly been used, but why??
        $idx = $remoteAddress->toString();
        $this->established[$idx] = $jsonRpc;
        $socket->onClose(function () use ($rpcIdx, $remoteAddress, $remoteName) {
            $this->removeClosedConnection($rpcIdx, $remoteAddress, $remoteName);
        });
        // Not yet: $routes = $jsonRpc->request('node.getActiveRoutes');
        // NodeList::fromSerialization ? Do we need NodeList?
        $this->updateRoutes($jsonRpc, $remoteName);
        $this->tellFeaturesAboutConnection($jsonRpc, $remoteName, $peerType);

        return $jsonRpc;
    }

    protected function removeClosedConnection($rpcIdx, SocketAddress $remoteAddress, string $remoteName): void
    {
        $idx = $remoteAddress->toString();
        unset($this->established[$idx]);
        unset($this->connections[$remoteAddress->toString()]);
        $this->node->nodeRouter->removePeerByName($remoteName);
        $this->tellFeaturesAboutLostConnection($remoteName);
        // Change information, if configured - otherwise forget ist
        $this->logger->notice(sprintf('Connection with %s has been closed', $remoteAddress->toString()));
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
    ): void {
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
    ): void {
        foreach ($this->features->getLoaded() as $feature) {
            $this->tellFeatureAboutLostConnection($feature, $peerIdentifier);
        }
    }

    protected function waitForPending(string $peerAddress): ?JsonRpcConnection
    {
        return $this->pending[$peerAddress]->getFuture()->await() ?? null;
    }

    protected function isPending(string $peerAddress): bool
    {
        return isset($this->pending[$peerAddress]);
    }

    protected function isConfigured(string $peerAddress): bool
    {
        return isset($this->configured[$peerAddress]);
    }

    protected function isFailing(string $peerAddress): bool
    {
        return isset($this->failing[$peerAddress]);
    }

    protected function getEstablishedConnection(string $peerAddress): ?JsonRpcConnection
    {
        return $this->established[$peerAddress] ?? null;
    }

    protected function onEstablishedConnection(string $peerAddress, ?string $fingerprint = null): ?JsonRpcConnection
    {
        if (! $this->isConfigured($peerAddress)) {
            $this->connections[$peerAddress] = new ConnectionInformation($peerAddress);
            $this->logger->debug('Connecting to ' . $peerAddress);
        }

        $socket = connect(
            "tcp://$peerAddress",
            $this->prepareClientContext($fingerprint),
            $this->stopper->getCancellation()
        );
        $socket->onClose(function () use ($peerAddress, $fingerprint) {
            if (isset($this->failing[$peerAddress])) {
                $this->logger->notice('Special case: GOT IT!');
                return;
            }
            if ($this->isConfigured($peerAddress)) {
                $this->reconnect($peerAddress, $fingerprint);
            }
        });

        return $this->onConnectionEstablished($socket, ConnectionDirection::OUTGOING)
            ?? throw new Exception('Failed to connect');
    }

    /**
     * Hint: this method might take a LONG time to complete
     *
     * @param string $peerAddress e.g. 192.0.2.10:5661
     * @throws CancelledException|ConnectException
     */
    public function connect(string $peerAddress, ?string $fingerprint = null): JsonRpcConnection
    {
        $this->logger->notice("Connection attempt: $peerAddress");

        if ($connection = $this->getEstablishedConnection($peerAddress)) {
            $this->logger->notice("$peerAddress: already established during call to connect()");
            return $connection;
        }
        if ($this->isPending($peerAddress)) {
            // TODO: remove log line
            $this->logger->notice("$peerAddress: connection already pending in connect()");
            return $this->waitForPending($peerAddress);
        }
        if ($this->isFailing($peerAddress)) {
            $this->logger->notice("$peerAddress: already failing, retrying immediately in connect()");
            EventLoop::cancel($this->failing[$peerAddress]);
            unset($this->failing[$peerAddress]);
            return $this->connect($peerAddress);
        }

        return $this->onEstablishedConnection($peerAddress, $fingerprint);
    }

    protected function reconnect(string $peerAddress, ?string $fingerprint = null): void
    {
        $this->logger->notice("Reconnecting to $peerAddress in 5s");
        Retry::forever(fn () => $this->connect(
            $peerAddress,
            $fingerprint
        ), "Reconnecting to $peerAddress", 30, 5, 30, $this->logger);
    }

    protected function forgetConfiguredConnection(string $peerAddress): void
    {
        unset($this->connections[$peerAddress]);
    }

    protected function disconnectEstablishedConnection(string $peerAddress): void
    {
        if ($connection = $this->getEstablishedConnection($peerAddress)) {
            unset($this->established[$peerAddress]);
            EventLoop::queue($connection->close(...));
        }
    }

    protected function stopPendingConnection(string $peerAddress): void
    {
        if (isset($this->pendingConnections[$peerAddress])) {
            $pending = $this->pending[$peerAddress];
            unset($this->pending[$peerAddress]);
            EventLoop::queue(function () use ($pending) {
                $pending->error(new Exception('Connection attempt has been stopped'));
            });
        }
    }

    protected function forgetFailingConnection(string $peerAddress): void
    {
        if (isset($this->failing[$peerAddress])) {
            EventLoop::cancel($this->failing[$peerAddress]);
            unset($this->failing[$peerAddress]);
        }
    }

    public function disconnect(string $peerAddress): void
    {
        $this->logger->notice('Disconnecting from ' . $peerAddress);
        $this->forgetConfiguredConnection($peerAddress);
        $this->disconnectEstablishedConnection($peerAddress);
        $this->stopPendingConnection($peerAddress);
        $this->forgetFailingConnection($peerAddress);
    }

    public function getConnections(): array
    {
        return array_values($this->connections);
    }

    protected function prepareBindContext(): BindContext
    {
        $certificate = $this->getMyConnectionCertificate();
        if (Certificate::fromPEM(PEM::fromFile($certificate->getCertFile()))->isSelfIssued()) {
            $cert = Certificate::fromPEM(PEM::fromFile($certificate->getCertFile()));
            $fingerprint = implode(':', str_split(strtoupper(sha1($cert->toDER())), 2));
            $this->logger->notice('Listening with a self-signed certificate. Fingerprint: ' . $fingerprint);
            // throw new Exception('Cannot listen with a self-signed certificate');

            $tlsContext = (new ServerTlsContext())
                ->withCaPath($this->trustStore->getCaPath())
                ->withoutPeerCapturing()
                ->withDefaultCertificate($certificate)
                ->withoutPeerVerification()
                ->withoutPeerNameVerification()
            ;
        } else {
            $tlsContext = (new ServerTlsContext())
                ->withCaPath($this->trustStore->getCaPath())
                ->withPeerCapturing()
                ->withDefaultCertificate($certificate)
                ->withPeerVerification()
                ->withoutPeerNameVerification()
            ;
        }

        return (new BindContext())->withTlsContext($tlsContext);
    }

    protected function prepareClientContext(?string $fingerprint = null): ConnectContext
    {
        $ampCertificate = $this->getMyConnectionCertificate();
        $certificate = Certificate::fromPEM(PEM::fromFile($ampCertificate->getCertFile()));

        if ($certificate->isSelfIssued()) {
            $certName = $this->certName;
            $ca = $this->ca();
            $caCert = $ca->getCertificate();

            if (
                !$this->trustStore->hasCaCertificate(
                    CertificateHelper::getSubjectName($caCert),
                    CertificateHelper::fingerprint($caCert)
                )
            ) {
                $this->logger->notice('Adding my own CA certificate to my truststore');
                $this->trustStore->addCaCertificate($ca->getCertificate());
            }
            $this->logger->notice('Signing my own certificate');
            $signed = $ca->sign(CertificateHelper::generateCsr($certName, $this->sslStore->readPrivateKey($certName)));
            $this->sslStore->store($signed, $this->sslStore->readPrivateKey($certName));
        }
        $tlsContext = (new ClientTlsContext())
            ->withCaPath($this->trustStore->getCaPath())
            ->withPeerCapturing()
            ->withCertificate($ampCertificate)
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

        $certificate = Certificate::fromPEM(PEM::fromFile($ssl->getCertificatePath($certName)));
        $now = new DateTimeImmutable();
        $notAfter = $certificate->tbsCertificate()->validity()->notAfter()->dateTime();
        $notBefore = $certificate->tbsCertificate()->validity()->notBefore()->dateTime();
        if ($notAfter < $now) {
            $this->logger->warning(
                'Dropping my certificate, it has expired at '
                . $notAfter->format('Y-m-d H:i:s')
            );
            $private = KeyGenerator::generate();
            $certificate = CertificateHelper::createTemporarySelfSigned($certName, $private);
            $ssl->store($certificate, $private);
        }
        if ($notBefore > $now) {
            $this->logger->warning(
                'Dropping my certificate, it is not valid before '
                . $notBefore->format('Y-m-d H:i:s')
            );
            $private = KeyGenerator::generate();
            $certificate = CertificateHelper::createTemporarySelfSigned($certName, $private);
            $ssl->store($certificate, $private);
        }

        return new AmpCertificate($ssl->getCertificatePath($certName), $ssl->getPrivateKeyPath($certName));
    }
}
