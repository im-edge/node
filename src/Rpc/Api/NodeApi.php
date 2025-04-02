<?php

namespace IMEdge\Node\Rpc\Api;

use Amp\Redis\RedisClient;
use IMEdge\CertificateStore\CertificateHelper;
use IMEdge\Config\Settings;
use IMEdge\DistanceRouter\RouteList;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Json\JsonString;
use IMEdge\Node\NodeRunner;
use IMEdge\Node\Rpc\ApiRunner;
use IMEdge\Node\Rpc\Routing\NodeList;
use IMEdge\RedisTables\RedisTables;
use IMEdge\RedisUtils\RedisResult;
use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Sop\CryptoEncoding\PEM;
use Sop\X509\Certificate\Certificate;

/**
 * TODO, listAvailableFeatures, enable/configureFeature, -> prefixed namespace
 */
#[ApiNamespace('node')]
class NodeApi
{
    protected ?RedisClient $redis = null;

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

    #[ApiMethod]
    public function getActiveRoutes(): RouteList
    {
        return $this->node->nodeRouter->getActiveRoutes();
    }

    #[ApiMethod]
    public function getDirectlyConnectedNodes(): NodeList
    {
        return $this->node->nodeRouter->directlyConnected;
    }

    #[ApiMethod]
    public function streamDbChanges(string $position): ?array
    {
        if ($this->redis === null) {
            return [];
        }
        $blockMs = 15000;
        $blockMs = 1;
        $maxCount = 10000;
        $xReadParams = ['XREAD', 'COUNT', (string) $maxCount, 'BLOCK', (string) $blockMs, 'STREAMS'];
        $params = array_merge($xReadParams, [
            RedisTables::STREAM_NAME_PREFIX . $this->node->identifier->uuid->toString()
        ], [$position]);
        $streams = $this->redis->execute(...$params);
        if ($streams === null) {
            return null;
        }

        return $streams[0];
    }

    /**
     * @param string $formerPos Former stream position, descending
     */
    #[ApiMethod]
    public function getDbStream(string $formerPos = '+'): array
    {
        if ($this->redis === null) {
            return [];
        }
        $stream = RedisTables::STREAM_NAME_PREFIX . $this->node->getUuid()->toString();
// TODO: pos - 1
        $result = [];
        foreach ($this->redis->execute('XREVRANGE', $stream, $formerPos, '-', 'COUNT', 1000) as [$streamPos, $row]) {
            $hash = RedisResult::toHash($row);
            $hash->keyProperties = JsonString::decode($hash->keyProperties);
            $hash->value = isset($hash->value) ? JsonString::decode($hash->value) : null;
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
        if ($persist) {
            $config = $this->node->requireConfig();
            $configured = $config->getArray(NodeRunner::CONFIG_LISTENERS);
            if (! isset($configured[$socket])) {
                $configured[$socket] = (object) [];
                $config->set(NodeRunner::CONFIG_LISTENERS, $configured);
                $this->node->storeConfig($config);
            }
        }
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
        if ($persist) {
            $config = $this->node->requireConfig();
            $configured = $config->getArray(NodeRunner::CONFIG_LISTENERS);
            if (! isset($listeners[$socket])) {
                $configured[$socket] = (object) [];
                $config->set(NodeRunner::CONFIG_LISTENERS, $configured);
                $this->node->storeConfig($config);
            }
        }
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
            $config = $this->node->requireConfig();
            $configured = $config->getArray(NodeRunner::CONFIG_PERSISTED_CONNECTIONS);
            if (! isset($configured[$peerAddress])) {
                $configured[$peerAddress] = (object) [];
                $config->set(NodeRunner::CONFIG_PERSISTED_CONNECTIONS, $configured);
                $this->node->storeConfig($config);
            }
        }
        $this->node->rpcConnections->connect($peerAddress);

        return true;
    }

    #[ApiMethod]
    public function disconnect(string $peerAddress, bool $persist): bool
    {
        if ($persist) {
            $config = $this->node->requireConfig();
            $configured = $config->getArray(NodeRunner::CONFIG_PERSISTED_CONNECTIONS);
            if (isset($configured[$peerAddress])) {
                unset($configured[$peerAddress]);
                $this->node->storeConfig($config);
                $config->set(NodeRunner::CONFIG_PERSISTED_CONNECTIONS, $configured);
                $this->node->storeConfig($config);
            }
        }
        $this->node->rpcConnections->disconnect($peerAddress);

        return true;
    }

    #[ApiMethod]
    public function getConnections(): array
    {
        return $this->node->nodeRouter->directlyConnected->jsonSerialize();
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
        $certName = $this->node->getUuid()->toString();
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
