<?php

namespace IMEdge\Node\Rpc\Api;

use Amp\Redis\RedisClient;
use gipfl\DataType\Settings;
use gipfl\Json\JsonString;
use IMEdge\CertificateStore\CertificateHelper;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\JsonRpc\JsonRpcConnection;
use IMEdge\Node\Inventory\RemoteInventory;
use IMEdge\Node\NodeRunner;
use IMEdge\Node\Rpc\ApiRunner;
use IMEdge\RedisUtils\RedisResult;
use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Revolt\EventLoop;
use Sop\CryptoEncoding\PEM;
use Sop\X509\Certificate\Certificate;

/**
 * TODO, listAvailableFeatures, enable/configureFeature, -> prefixed namespace
 */
#[ApiNamespace('node')]
class NodeApi
{
    protected RedisClient $redis;

    public function __construct(
        protected NodeRunner $node,
        protected ApiRunner $apiRunner,
        protected LoggerInterface $logger
    ) {
        EventLoop::queue(function () {
            $this->redis = $this->node->services->getRedisClient('WebAccess');
        });
    }

    #[ApiMethod]
    public function getSettings(): Settings
    {
        return $this->node->requireConfig();
    }

    #[ApiMethod]
    public function getIdentifier(): NodeIdentifier
    {
        return $this->node->identifier;
    }

    #[ApiMethod]
    public function getName(): string
    {
        return $this->node->getName();
    }

    #[ApiMethod]
    public function getUuid(): string
    {
        return $this->node->getUuid()->toString();
    }

    #[ApiMethod]
    public function getAvailableMethods(): array
    {
        return $this->apiRunner->getKnownMethods();
    }

    /**
     * @param string $formerPos Former stream position, descending
     */
    #[ApiMethod]
    public function getDbStream(string $formerPos = '+'): array
    {
        $stream = 'db-stream-' . $this->node->getUuid()->toString();
// TODO: pos - 1
        $result = [];
        foreach ($this->redis->execute('XREVRANGE', $stream, $formerPos, '-', 'COUNT', 1000) as [$streamPos, $row]) {
            $hash = RedisResult::toHash($row);
            $hash->keyProperties = JsonString::decode($hash->keyProperties);
            $hash->value = JsonString::decode($hash->value);
            $result[$streamPos] = $hash;
        }

        return $result;
    }

    /**
     * Listen on a given TCP socket address
     */
    #[ApiMethod]
    public function listen(string $socket, ?bool $persist = false): bool
    {
        /*if ($persist) {
            $this->logger->notice('Pers req');
            $config = $this->node->requireConfig();
            $connections = $config->getArray(NodeRunner::CONFIG_PERSISTED_CONNECTIONS);
            if (! isset($connections[$peerAddress])) {
                $connections[$peerAddress] = (object) [];
                $config->set(NodeRunner::CONFIG_PERSISTED_CONNECTIONS, $connections);
                $this->node->storeConfig($config);
            }
        }*/
        if (in_array($socket, $this->node->rpcConnections->listListeners())) {
            throw new \InvalidArgumentException("Node is already listening on $socket");
        }
        $this->logger->notice("Binding tcp://$socket");
        $bind = $this->node->rpcConnections->listen('tcp://' . $socket);
        EventLoop::queue(function () use ($socket, $bind) {
            try {
                foreach ($bind as $connection) {
                    // TODO: Move this loop elsewhere
                    $this->logger->notice('Got a connection on tcp://' . $socket);
                }
                // Socket stopped.
            } catch (\Throwable $e) {
                $this->logger->notice("Socket tcp://$socket failed: " . $e->getMessage());
            }
        });

        return true;
    }

    /**
     * Stop listening on the given TCP socket address
     */
    #[ApiMethod]
    public function stopListening(string $socket, ?bool $persist = false): bool
    {
        /*if ($persist) {
            $this->logger->notice('Pers req');
            $config = $this->node->requireConfig();
            $connections = $config->getArray(NodeRunner::CONFIG_PERSISTED_CONNECTIONS);
            if (! isset($connections[$peerAddress])) {
                $connections[$peerAddress] = (object) [];
                $config->set(NodeRunner::CONFIG_PERSISTED_CONNECTIONS, $connections);
                $this->node->storeConfig($config);
            }
        }*/
        $this->logger->notice("Closing tcp://$socket");
        return $this->node->rpcConnections->stopListener('tcp://' . $socket);
    }

    /**
     * Get a list of currently active TCP listeners
     */
    #[ApiMethod]
    public function listListeners(): array
    {
        return $this->node->rpcConnections->listListeners();
    }

    #[ApiMethod]
    public function connect(string $peerAddress, bool $persist): bool
    {
        if ($persist) {
            $this->logger->notice('Pers req');
            $config = $this->node->requireConfig();
            $connections = $config->getArray(NodeRunner::CONFIG_PERSISTED_CONNECTIONS);
            if (! isset($connections[$peerAddress])) {
                $connections[$peerAddress] = (object) [];
                $config->set(NodeRunner::CONFIG_PERSISTED_CONNECTIONS, $connections);
                $this->node->storeConfig($config);
            }
        }
        $this->node->connectionHandler->connect($peerAddress);

        return true;
    }

    #[ApiMethod]
    public function disconnect(string $peerAddress, bool $persist): bool
    {
        if ($persist) {
            $config = $this->node->requireConfig();
            $connections = $config->getArray(NodeRunner::CONFIG_PERSISTED_CONNECTIONS);
            if (isset($connections[$peerAddress])) {
                unset($connections[$peerAddress]);
                $this->node->storeConfig($config);
                $config->set(NodeRunner::CONFIG_PERSISTED_CONNECTIONS, $connections);
                $this->node->storeConfig($config);
            }
        }
        $this->node->connectionHandler->disconnect($peerAddress);

        return true;
    }

    #[ApiMethod]
    public function getConnections(): array
    {
        return $this->node->nodeRouter->directlyConnected->jsonSerialize();
        return $this->node->connectionHandler->getConnections();
    }

    #[ApiMethod]
    public function setRemoteInventory(
        JsonRpcConnection $connection,
        UuidInterface $datanodeUuid,
        array $tablePositions
    ): bool {
        $this->logger->notice('GOT REMOTE INVENTORY');
        $this->node->setCentralInventory(new RemoteInventory(
            $connection,
            $datanodeUuid,
            $tablePositions,
            $this->logger
        ));

        return true;
    }
/*
    public function getDataNodeConnectionsRequest(): array
    {
        return $this->node->dataNodeConnections->getConnections();
    }

    public function getEstablishedConnectionsRequest(): object
    {
        $result = [];
        foreach ($this->node->establishedConnections as $name => $connection) {
            $result[$name] = (object) [
                'name' => $name,
                'destination' => $connection->destination,
            ];
        }

        return (object) $result;
    }
*/

    #[ApiMethod]
    public function addTrustedCa(string $caCertificate): bool
    {
        $this->node->rpcConnections->trustStore->addCaCertificate(
            Certificate::fromPEM(PEM::fromString($caCertificate))
        );

        return true;
    }

    #[ApiMethod]
    public function getCsr(): string
    {
        $certName = $this->node->getName();
        $sslStore = $this->node->rpcConnections->sslStore;

        return CertificateHelper::generateCsr($certName, $sslStore->readPrivateKey($certName));
    }

    #[ApiMethod]
    public function setSignedCertificate(string $certificate): bool
    {
        $sslStore = $this->node->rpcConnections->sslStore;
        $sslStore->writeCertificate(
            Certificate::fromPEM(PEM::fromString($certificate))
        );

        return true;
    }

    #[ApiMethod]
    public function restart(): bool
    {
        // Grant some time to ship the response
        EventLoop::delay(0.1, function () {
            $this->node->restart();
        });

        return true;
    }

    #[ApiMethod]
    public function getFeatures(): object
    {
        $features = [];
        foreach ($this->node->getFeatures()->getLoaded() as $loaded) {
            $features[$loaded->name] = (object) [
                'name'       => $loaded->name,
                'directory'  => $loaded->directory,
                'registered' => $loaded->isRegistered(),
                'enabled'    => true,
            ];
        }

        return (object) $features;
    }

    #[ApiMethod]
    public function enableFeature(string $name, string $sourcePath): bool
    {
        $features = $this->node->getFeatures();
        $features->enable($name, $sourcePath);
        $features->load($name, $sourcePath);
        return true;
    }
}
