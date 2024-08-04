<?php

namespace IMEdge\Node;

use Amp\DeferredFuture;
use IMEdge\CertificateStore\CaStore\CaStoreDirectory;
use IMEdge\CertificateStore\CertificationAuthority;
use IMEdge\CertificateStore\ClientStore\ClientSslStoreDirectory;
use IMEdge\Inventory\CentralInventory;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Node\Monitoring\InternalMetricsCollection;
use IMEdge\Node\Network\ConnectionHandler;
use IMEdge\Node\Network\DataNodeConnections;
use IMEdge\Node\Rpc\Api\NodeApi;
use IMEdge\Node\Rpc\Api\NtpApi;
use IMEdge\Node\Rpc\ApiRunner;
use IMEdge\Node\Rpc\ControlConnections;
use IMEdge\Node\Rpc\Routing\Node;
use IMEdge\Node\Rpc\Routing\NodeRouter;
use IMEdge\Node\Rpc\RpcConnections;
use IMEdge\Node\UtilityClasses\DirectoryBasedComponent;
use IMEdge\RedisRunner\RedisRunner;
use IMEdge\RedisTables\RedisTableSubscriber;
use IMEdge\SimpleDaemon\DaemonComponent;
use IMEdge\SimpleDaemon\Process;
use Revolt\EventLoop;
use RuntimeException;

use function Amp\async;
use function Amp\Future\awaitAll;
use function gethostbyaddr;
use function gethostbyname;
use function gethostname;

class NodeRunner implements DaemonComponent
{
    use DirectoryBasedComponent;

    protected const CONFIG_TYPE = 'IMEdge/Node';
    protected const CONFIG_FILE_NAME = 'node.json';
    protected const CONFIG_VERSION = 'v1';
    protected const DOT_DIR = '.imedge';
    protected const SUPPORTED_CONFIG_VERSIONS = [
        self::CONFIG_VERSION,
    ];
    protected const SOCKET_PATH = '/run/imedge';
    public const SOCKET_FILE = self::SOCKET_PATH . '/node.sock';
    public const CONFIG_PERSISTED_CONNECTIONS = 'connections';
    protected const DEFAULT_REDIS_BINARY = '/usr/bin/redis-server';

    public readonly Features $features;
    public readonly DataNodeConnections $dataNodeConnections;
    public readonly ConnectionHandler $connectionHandler;
    public readonly NodeIdentifier $identifier;
    public readonly Events $events;
    public readonly Services $services;
    protected ?CertificationAuthority $ca = null;
    protected ?RedisRunner $redisRunner = null;
    /** @var RedisTableSubscriber[] */
    protected array $redisTableSubscribers = [];
    protected DeferredFuture|string $redisSocket;
    protected array $newComponents = [];
    public RpcConnections $rpcConnections;
    public NodeRouter $nodeRouter;
    public readonly ApiRunner $controlApi;
    protected ControlConnections $controlSocket;

    protected function construct(): void
    {
        $this->redisSocket = new DeferredFuture();
    }

    public function start(): void
    {
        $this->run(); // Calls initialize() from DirectoryBasedComponent... not so obvious
    }

    protected function initialize(): void
    {
        if ($this->isCa()) {
            $this->initializeCa();
        }
        $this->events = new Events();
        $this->services = new Services($this, $this->logger);
        $this->identifier = new NodeIdentifier($this->getUuid(), $this->name, self::getFqdn());
        EventLoop::queue(function () {
            $internalMetrics = new InternalMetricsCollection(
                $this->identifier,
                $this->events,
                $this->services,
                $this->logger
            );
            $internalMetrics->start();
            $this->newComponents['internalMetrics'] = $internalMetrics;
        });
        $this->nodeRouter = new NodeRouter(new Node($this->identifier->uuid, $this->identifier->name), $this->logger);
        $this->dataNodeConnections = new DataNodeConnections($this, $this->logger);
        $this->rpcConnections = new RpcConnections($this, null, $this->logger);
        $this->connectionHandler = new ConnectionHandler($this->dataNodeConnections, $this->logger);
        EventLoop::queue($this->launchRedis(...));
        $this->initializeRemoteControl();
        $this->features = Features::initialize($this, $this->logger);
        $this->connectionHandler->setConfiguredConnections(
            $this->requireConfig()->getArray(self::CONFIG_PERSISTED_CONNECTIONS)
        );
    }

    protected function initializeRemoteControl(): void
    {
        $this->controlApi = $api = new ApiRunner(
            $this->identifier->uuid->toString(),
            $this->nodeRouter->directlyConnected
        );
        $api->addApi(new NtpApi());
        $api->addApi(new NodeApi($this, $api, $this->logger));
        $this->controlSocket = new ControlConnections($this, $this->controlApi, $this->logger);
        $this->controlSocket->bind(self::SOCKET_FILE);
    }

    public function stop(): void
    {
        foreach ($this->redisTableSubscribers as $subscriber) {
            $subscriber->stop();
        }
        $pending = [
            async($this->features->shutdown(...))
        ];
        if ($this->redisRunner) {
            $pending[] = async($this->redisRunner->stop(...));
        }

        awaitAll($pending);
    }

    public function restart(): void
    {
        $this->stop();
        $this->logger->notice('Shutdown completed, restarting myself');
        EventLoop::delay(0.2, Process::restart(...));
    }

    public function setCentralInventory(CentralInventory $inventory): void
    {
        $this->logger->notice('This node got an Inventory');
        foreach ($this->redisTableSubscribers as $name => $subscriber) {
            $this->logger->notice('Stopping Redis Table Subscriber: ' . $name);
            $subscriber->stop();
        }
        $this->redisTableSubscribers = [];
        $tables = $inventory->loadTableSyncPositions($this->identifier);
        $this->logger->notice('DataNode got table positions: ' . count($tables));
        $this->redisTableSubscribers['main'] = new RedisTableSubscriber(
            $this->getRedisSocket(),
            $this->getUuid(),
            $this->logger
        );
        foreach ($tables as $table => $position) {
            $this->redisTableSubscribers['main']->setStreamPosition($table, $position);
        }
        foreach ($this->redisTableSubscribers as $subscriber) {
            $subscriber->setCentralInventory($inventory);
        }
    }

    public function getFeatures(): Features
    {
        return $this->features;
    }

    protected function initializeCa(): void
    {
        $directory = $this->getConfigDir() . '/CA';
        // Directory::requireWritable($directory, false, 0700);
        $caStore = new CaStoreDirectory($directory);
        $this->ca = new CertificationAuthority(Application::PROCESS_NAME . '::CA', $caStore);
        echo $this->ca->getCertificate()->toPEM();
    }

    public function getCA(): CertificationAuthority
    {
        if ($this->ca === null) {
            throw new RuntimeException('This node is not a CertificationAuthority');
        }

        return $this->ca;
    }

    protected function isCa(): bool
    {
        return false;
    }

    protected function initializeClientSsl(): void
    {
        $directory = $this->getConfigDir() . '/ssl';
        $sslStore = new ClientSslStoreDirectory($directory);
        if ($this->isCa()) {
            $sslStore->writeCaCertificate($this->ca->getCertificate());
        }

        echo $sslStore->readCaCertificate()?->toPEM();
    }

    protected function launchRedis(): void
    {
        $this->redisRunner = new RedisRunner(
            static::getRedisBinary(),
            $this->getBaseDir() . '/redis',
            $this->logger
        );
        $this->redisRunner->run();
        $socket = $this->redisRunner->getRedisSocket();
        $this->logger->notice('Redis is ready and listening on ' . $socket);
        $deferred = $this->redisSocket;
        $this->redisSocket = 'unix://' . $socket;
        $deferred->complete($this->redisSocket);
    }

    public function getRedisSocket(): string
    {
        if ($this->redisSocket instanceof DeferredFuture) {
            return $this->redisSocket->getFuture()->await();
        }

        return $this->redisSocket;
    }

    protected function generateName(): string
    {
        if ($fqdn = self::getFqdn()) {
            return $fqdn;
        }

        throw new RuntimeException('Node name has not been set, FQDN detection failed');
    }

    protected function getFqdn(): string
    {
        // TODO: Timeout? Error? Async
        return gethostbyaddr(gethostbyname(gethostname()));
    }

    protected static function getRedisBinary(): string
    {
        return static::DEFAULT_REDIS_BINARY;
    }
}
