<?php

namespace IMEdge\Node\Rpc;

use Amp\CancelledException;
use Amp\DeferredCancellation;
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
use IMEdge\CertificateStore\CertificateHelper;
use IMEdge\CertificateStore\ClientStore\ClientSslStoreDirectory;
use IMEdge\CertificateStore\ClientStore\ClientSslStoreInterface;
use IMEdge\CertificateStore\Generator\KeyGenerator;
use IMEdge\CertificateStore\TrustStore\TrustStoreDirectory;
use IMEdge\JsonRpc\JsonRpcConnection;
use IMEdge\JsonRpc\RequestHandler;
use IMEdge\Node\NodeRunner;
use IMEdge\Protocol\NetString\NetStringConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Sop\CryptoEncoding\PEM;
use Sop\X509\Certificate\Certificate;

use function Amp\Socket\connect;
use function Amp\Socket\listen;

class RpcConnections
{
    public ClientSslStoreInterface $sslStore;
    protected string $certName;
    protected TrustStoreDirectory $trustStore;

    /** @var JsonRpcConnection[] */
    protected array $connections = [];
    /** @var ResourceServerSocket[] */
    protected array $listeners = [];
    protected DeferredCancellation $stopper;

    public function __construct(
        protected readonly NodeRunner $node,
        protected readonly ?RequestHandler $requestHandler = null,
        protected readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $basedir = $this->node->getConfigDir();
        $this->sslStore = new ClientSslStoreDirectory("$basedir/ssl");
        $this->trustStore = new TrustStoreDirectory("$basedir/trust");
        $this->certName = $node->getName();
        $this->stopper = new DeferredCancellation();
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
            if ($rpc = $this->connectionEstablished($socket)) {
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

    protected function connectionEstablished(Socket $socket): ?JsonRpcConnection
    {
        $remoteAddress = $socket->getRemoteAddress();
        $peerType = RpcPeerType::ANONYMOUS;

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
            foreach ($socket->getTlsInfo()->getPeerCertificates() as $certificate) {
                var_dump($certificate);
                $cert = Certificate::fromPEM(PEM::fromString($certificate->toPem()));
                if ($this->trustStore->isValid($cert)) {
                    $peerType = RpcPeerType::PEER;
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
            // return null;
            // TODO: Normally we should not fall through
        }

        $netString = new NetStringConnection($socket, $socket);
        $jsonRpc = new JsonRpcConnection($netString, $netString, $this->requestHandler);

        $idx = spl_object_id($jsonRpc);
        $this->connections[$idx] = $jsonRpc;
        $socket->onClose(function () use ($idx, $remoteAddress) {
            unset($this->connections[$idx]);
            $this->logger->notice(sprintf('Connection with %s has been closed', $remoteAddress->toString()));
        });

        return $jsonRpc;
    }

    /**
     * @param string $target e.g. tcp://127.0.0.1:5661
     * @param string|null $fingerprint
     * @throws CancelledException|ConnectException
     */
    public function connect(string $target, ?string $fingerprint = null): JsonRpcConnection
    {
        $socket = connect($target, $this->prepareClientContext($fingerprint), $this->stopper->getCancellation());

        return $this->connectionEstablished($socket);
    }

    protected function prepareBindContext(): BindContext
    {
        $tlsContext = (new ServerTlsContext())
            //->withCaFile($this->trustStore->getCaPath() . '/a_cert.pem')
            ->withCaPath($this->trustStore->getCaPath())
            ->withPeerCapturing()
            ->withPeerVerification()
            ->withDefaultCertificate($this->getMyConnectionCertificate())
            //->withoutPeerVerification()
            ;

        return (new BindContext())->withTlsContext($tlsContext);
    }

    protected function prepareClientContext(?string $fingerprint = null): ConnectContext
    {
        $tlsContext = (new ClientTlsContext())
            ->withCaFile($this->trustStore->getCaPath() . '/ca_cert.pem')
            ->withCaPath($this->trustStore->getCaPath())
            ->withPeerCapturing() ->withSni()
            ->withCertificate($this->getMyConnectionCertificate())
            // ->withSni()->withPeerName($this->certName)
            ->withoutPeerVerification()
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
