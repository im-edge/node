<?php

namespace IMEdge\Node;

use Evenement\EventEmitterTrait;
use gipfl\DataType\Settings;
use gipfl\Json\JsonString;
use gipfl\Log\PrefixLogger;
use IMEdge\Filesystem\Directory;
use IMEdge\Inventory\CentralInventory;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Node\FeatureRegistration\RpcRegistrationSubscriberInterface;
use IMEdge\Node\Network\ConnectionSubscriberInterface;
use IMEdge\Node\Rpc\Routing\Node;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Amp\Future\await;
use function Amp\Future\awaitAll;
use function React\Async\await as reactAwait;

final class Feature
{
    use EventEmitterTrait;

    public const ON_NODE_CONNECTED = 'nodeConnected';
    public const ON_NODE_DISCONNECTED = 'nodeDisconnected';
    public const ON_CONNECTION = 'connection';
    public const ON_CONNECTION_REMOVED = 'connectionRemoved';
    public const ON_INVENTORY_REGISTERED = 'inventory_registered';

    /** @var array<string, object> */
    private array $registeredRpcNamespaces = [];
    /** @var object */
    private array $registeredRpcApis = [];
    /** @var ConnectionSubscriberInterface[] */
    private array $connectionSubscribers = [];
    /** @var RpcRegistrationSubscriberInterface[] */
    private array $rpcRegistrationSubscribers = [];
    private array $onShutdown = [];
    private bool $isRegistered = false;
    protected LoggerInterface $logger;
    public Settings $settings;

    final public function __construct(
        public readonly string $name,
        public readonly string $directory,
        protected readonly string $configFile,
        public readonly NodeIdentifier $nodeIdentifier,
        public readonly Services $services,
        protected readonly Events $events, // TODO: remove?
        LoggerInterface $logger,
    ) {
        $this->initializeSettings();
        $this->logger = new PrefixLogger("[$name] ", $logger);
    }

    private function initializeSettings(): void
    {
        if (file_exists($this->configFile)) {
            $this->settings = Settings::fromSerialization(JsonString::decode(file_get_contents($this->configFile)));
        } else {
            $this->settings = new Settings();
        }
    }

    public function shutdown(): void
    {
        $promises = [];
        $this->logger->notice('Shutting down ' . $this->name);
        foreach ($this->onShutdown as $callable) {
            $promises[] = async(function () use ($callable) {
                try {
                    $result = $callable();
                } catch (Throwable $e) {
                    $this->logger->error(sprintf(
                        'Error during shutting down for feature "%s": %s',
                        $this->name,
                        $e->getMessage()
                    ));

                    return null;
                }

                if ($result instanceof PromiseInterface) {
                    $result = reactAwait($result);
                }
                return $result;
            });
        }

        awaitAll($promises);
    }

    /**
     * @api
     */
    public function storeSettings(): void
    {
        Directory::requireWritable(dirname($this->configFile));
        file_put_contents($this->configFile, JsonString::encode($this->settings, JSON_PRETTY_PRINT));
    }

    final public function register(): void
    {
        if ($this->isRegistered) {
            throw new RuntimeException(
                'Feature cannot be registered twice: ' . $this->name
            );
        }
        $featureFile = $this->directory . '/feature.php';
        if (file_exists($featureFile)) {
            try {
                await([async(function () use ($featureFile) {
                    include $featureFile;
                })]);
            } catch (Throwable $e) {
                $this->logger->error(sprintf(
                    'Failed to register feature %s: %s (%s:%s)',
                    $this->name,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
                try {
                    $this->shutdown();
                } catch (Throwable $e) {
                    $this->logger->error($e->getMessage());
                }
                return;
            }
            $this->logger->info('Feature registered: ' . $this->name);
        } else {
            $this->logger->info(sprintf(
                'Feature registered, has no feature file in %s: %s',
                $this->directory,
                $this->name
            ));
        }
        $this->isRegistered = true;
    }

    final public function isRegistered(): bool
    {
        return $this->isRegistered;
    }

    /**
     * @api
     */
    final protected function onShutdown(callable $callback): void
    {
        $this->onShutdown[] = $callback;
    }

    /**
     * TODO: reject registration, once feature loading has been completed
     * @deprecated
     * @api
     */
    final protected function registerRpcNamespace(string $namespace, object $handler): void
    {
        if ($handler instanceof LoggerAwareInterface) {
            // TODO: prefixLogger
            $handler->setLogger($this->logger);
        }
        $this->registeredRpcNamespaces[$namespace] = $handler;
    }

    final protected function registerRpcApi(object $handler): void
    {
        $this->registeredRpcApis[] = $handler;
    }

    /**
     * @api
     */
    final protected function subscribeRpcRegistrations(RpcRegistrationSubscriberInterface $subscriber): void
    {
        $this->rpcRegistrationSubscribers[] = $subscriber;
    }

    /**
     * @api
     */
    final protected function subscribeConnections(ConnectionSubscriberInterface $connectionSubscriber): void
    {
        $this->logger->notice('Got a new connection subscriber');
        $this->connectionSubscribers[] = $connectionSubscriber;
    }

    /**
     * @deprecated
     * @return array<string, object>
     */
    final public function getRegisteredRpcNamespaces(): array
    {
        return $this->registeredRpcNamespaces;
    }

    /**
     * @return object[]
     */
    final public function getRegisteredRpcApis(): array
    {
        return $this->registeredRpcApis;
    }

    /**
     * @return RpcRegistrationSubscriberInterface[]
     */
    final public function getRpcRegistrationSubscribers(): array
    {
        return $this->rpcRegistrationSubscribers;
    }

    /**
     * @return ConnectionSubscriberInterface[]
     */
    final public function getConnectionSubscribers(): array
    {
        return $this->connectionSubscribers;
    }

    final public function connectNode(Node $node): void
    {
        $this->emit(self::ON_NODE_CONNECTED, [$node]);
    }

    final public function disconnectNode(Node $node): void
    {
        $this->emit(self::ON_NODE_DISCONNECTED, [$node]);
    }

    final protected function registerInventory(CentralInventory $inventory): void
    {
        EventLoop::queue(function () use ($inventory) {
            // TODO: verify, that this is late enough!
            try {
                $this->emit(self::ON_INVENTORY_REGISTERED, [$inventory]);
                $this->logger->notice('Inventory has been registered');
            } catch (Throwable $e) {
                $this->logger->error('Inventory registration failed: ' . $e->getMessage());
            }
        });
    }

    final public function getBinaryFile(string $binaryFile): string
    {
        $binaryPath = $this->directory . '/bin';
        $binary = "$binaryPath/$binaryFile";
        if (! file_exists($binary)) {
            throw new RuntimeException("Could not find required executable '$binary' in '$binaryPath'");
        }
        if (! is_executable($binary)) {
            throw new RuntimeException("Cannot execute '$binaryPath/$binary'");
        }

        return $binary;
    }
}
