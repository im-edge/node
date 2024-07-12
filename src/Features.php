<?php

namespace IMEdge\Node;

use DirectoryIterator;
use gipfl\Protocol\JsonRpc\JsonRpcConnection as OldJsonRpcConnection;
use IMEdge\Filesystem\Directory;
use IMEdge\Inventory\CentralInventory;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Node\Network\ConnectionHandler;
use IMEdge\Node\Network\DataNodeConnections;
use IMEdge\Node\Rpc\Routing\Node;
use IMEdge\Node\Rpc\Routing\NodeRouter;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use SplFileInfo;

use function Amp\async;
use function Amp\Future\awaitAll;

class Features
{
    /** @var Feature[] */
    protected array $loaded = [];

    public function __construct(
        protected readonly NodeIdentifier $nodeIdentifier,
        protected readonly NodeRouter $nodeRouter,
        protected readonly ConnectionHandler $connectionHandler,
        protected readonly DataNodeConnections $dataNodeConnections,
        protected readonly Services $services,
        protected readonly Events $events,
        protected string $baseDirectory,
        protected readonly LoggerInterface $logger
    ) {
        Directory::requireWritable($this->getFeatureConfigDirectory());
        Directory::requireWritable($this->getEnabledFeaturesDirectory());
    }

    public static function initialize(NodeRunner $node, LoggerInterface $logger): Features
    {
        $features = new Features(
            $node->identifier,
            $node->nodeRouter,
            $node->connectionHandler,
            $node->dataNodeConnections,
            $node->services,
            $node->events,
            $node->getConfigDir(),
            $logger
        );
        EventLoop::queue(function () use ($features, $node) {
            $features->loadAll($node);

            foreach ($features->getLoaded() as $feature) {
                $features->tellSubscribersAboutLoadedFeature($feature);
                foreach ($feature->getRegisteredRpcApis() as $rpcApi) {
                    try {
                        $node->controlApi->addApi($rpcApi);
                    } catch (\Throwable $e) {
                        $features->logger->error(sprintf(
                            'Failed to register API for feature %s: %s',
                            $feature->name,
                            $e->getMessage()
                        ));
                    }
                }
            }
            // TODO: enable one per one, to allow enabling via API
            // $this->remoteApi->setFeatures($this->features);
        });

        return $features;
    }

    public function tellSubscribersAboutLoadedFeature(Feature $feature): void
    {
        $this->logger->notice('Features::tellSubscribersAboutLoadedFeature:' . $feature->name);
        // TODO: unfinished, wrong!!
        foreach ($feature->getRpcRegistrationSubscribers() as $handler) {
            // foreach ($this->features->getLoaded() as $loaded) {
            // $loaded->on(Feature::ON_INVENTORY_REGISTERED, function (CentralInventory $inventory) {
            // $this->setCentralInventory($inventory);
            // });
            foreach ($feature->getRegisteredRpcNamespaces() as $registered => $nsHandler) {
                $handler->registerRpcNamespace($registered, $nsHandler);
            }
            //}
        }
    }

    public function getEnabledFeaturesDirectory(): string
    {
        return $this->baseDirectory . '/features-enabled';
    }

    public function getFeatureConfigDirectory(): string
    {
        return $this->baseDirectory . '/feature';
    }

    public function loadAll(NodeRunner $node): void
    {
        foreach ($this->enumEnabled() as $name => $directory) {
            $this->load($name, $directory);
        }
        foreach ($this->getLoaded() as $feature) {
            $this->tellSubscribersAboutLoadedFeature($feature);
            $this->applyFeatureEventHandlers($feature, $node);
        }
    }

    public function applyFeatureEventHandlers(Feature $feature, NodeRunner $node): void
    {
        $this->logger->notice('DataNode::applyFeatureEventHandlers:' . $feature->name);
        $feature->on(Feature::ON_INVENTORY_REGISTERED, function (CentralInventory $inventory) use ($node) {
            $node->setCentralInventory($inventory);
        });
    }

    public function hasLoaded(string $name): bool
    {
        return isset($this->loaded[$name]);
    }

    public function load(string $name, string $directory): void
    {
        if ($this->hasLoaded($name)) {
            $this->logger->error("Cannot load feature $name twice");
        }
        $this->loaded[$name] = $feature = new Feature(
            $name,
            $directory,
            $this->baseDirectory . "/feature/$name.json",
            $this->nodeIdentifier,
            $this->services,
            $this->events,
            $this->logger
        );
        // The feature registered an RPC connection. This allows talking to metric instances
        $feature->on(Feature::ON_CONNECTION, $this->onConnectionRegisteredByFeature(...));
        $feature->on(Feature::ON_CONNECTION_REMOVED, $this->onConnectionRemovedByFeature(...));
        $feature->on(Feature::ON_NODE_CONNECTED, $this->onNodeConnectedByFeature(...));
        $feature->on(Feature::ON_NODE_DISCONNECTED, $this->onNodeDisconnectedByFeature(...));
        $feature->register();
    }

    protected function onNodeConnectedByFeature(Node $node): void
    {
        $this->nodeRouter->addPeer($node);
    }

    protected function onNodeDisconnectedByFeature(Node $node): void
    {
        $this->nodeRouter->removePeer($node);
    }

    protected function onConnectionRegisteredByFeature(
        OldJsonRpcConnection $connection,
        string $peerAddress
    ): void {
        $this->connectionHandler->registerConnected($connection, $peerAddress);
        $this->dataNodeConnections->onConnectedPeer(
            $this->connectionHandler,
            $connection,
            $peerAddress
        );
    }

    protected function onConnectionRemovedByFeature(
        string $peerAddress
    ): void {
        $this->connectionHandler->removeConnected($peerAddress);
        $this->dataNodeConnections->onDisconnect($peerAddress);
    }

    public function shutdown(): void
    {
        $futures = [];
        foreach ($this->loaded as $feature) {
            $futures[] = async($feature->shutdown(...));
        }

        awaitAll($futures);
    }

    /**
     * @return array<string, Feature>
     */
    public function getLoaded(): array
    {
        return $this->loaded;
    }

    public function hasEnabled($name): bool
    {
        return file_exists($this->getEnabledFeaturesDirectory() . "/$name");
    }

    public function enable(string $name, string $sourcePath): void
    {
        if (! preg_match('/^[a-z]{3,16}$/', $name)) {
            throw new InvalidArgumentException("'$name' is not a valid feature name");
        }
        if ($this->hasLoaded($name)) {
            throw new InvalidArgumentException("Feature $name has already been loaded");
        }

        $sourcePath = rtrim($sourcePath, '/');
        if (! file_exists($sourcePath) || ! is_readable($sourcePath)) {
            throw new InvalidArgumentException("There is no readable feature at $sourcePath");
        }
        if ($this->hasEnabled($name)) {
            throw new InvalidArgumentException("Feature $name has already been enabled");
        }

        $featureFile = "$sourcePath/feature.php";
        if (! file_exists($featureFile) || ! is_readable($featureFile)) {
            throw new InvalidArgumentException(sprintf(
                "Path %s exists, but doesn't seem to be an %s feature",
                $sourcePath,
                Defaults::APPLICATION_NAME
            ));
        }
        $link = $this->getEnabledFeaturesDirectory() . "/$name";
        if (!symlink("$sourcePath/", $link)) {
            throw new InvalidArgumentException("Failed to link $sourcePath to $link");
        }
    }

    protected function enumEnabled(): array
    {
        $modules = [];
        $directory = $this->getEnabledFeaturesDirectory();
        if (!is_dir($directory)) {
            return $modules;
        }
        if (!is_readable($directory)) {
            $this->logger->error("$directory is not readable");
        }
        foreach (new DirectoryIterator($directory) as $fileInfo) {
            if ($fileInfo->isLink() && ctype_alnum($fileInfo->getBasename())) {
                $target = new SplFileInfo($fileInfo->getLinkTarget());
                if ($target->isDir() && $target->isReadable()) {
                    $modules[$fileInfo->getBasename()] = $target->getPathname();
                }
            }
        }

        return $modules;
    }
}
