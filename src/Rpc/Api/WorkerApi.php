<?php

namespace IMEdge\Node\Rpc\Api;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use IMEdge\Config\Settings;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Node\Application;
use IMEdge\Node\ImedgeWorker;
use IMEdge\Node\Rpc\ApiRunner;
use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;
use IMEdge\SimpleDaemon\DaemonComponent;
use IMEdge\SimpleDaemon\Process;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Throwable;

use function Amp\async;
use function Amp\Future\awaitAll;

#[ApiNamespace('worker')]
class WorkerApi implements EventEmitterInterface, DaemonComponent
{
    use EventEmitterTrait;

    public const ON_ERROR = 'error';
    public const ON_SHUTDOWN_REQUESTED = 'shutdown';

    protected array $workerInstances = [];

    public function __construct(
        protected UuidInterface $uuid,
        protected NodeIdentifier $nodeIdentifier,
        protected LoggerInterface $logger,
        protected ApiRunner $apiRunner,
    ) {
    }

    #[ApiMethod]
    public function getUuid(): UuidInterface
    {
        return $this->uuid;
    }

    #[ApiMethod]
    public function setWorkerName(string $name): bool
    {
        Process::setTitle(Application::PROCESS_NAME . "::worker::$name");
        return true;
    }

    /**
     * @param class-string<ImedgeWorker> $className
     * @param Settings|null $settings
     * @return bool
     */
    #[ApiMethod]
    public function launch(string $className, ?Settings $settings = null): bool
    {
        // TODO: Option for restartOnFailure?
        if (!class_exists($className)) {
            throw new InvalidArgumentException("There is no such class: $className");
        }
        if (! is_a($className, ImedgeWorker::class, true)) {
            throw new InvalidArgumentException("$className is not an ImedgeWorker");
        }
        $instance = new $className($settings ?? new Settings(), $this->nodeIdentifier, $this->logger);
        try {
            $instance->start();
            foreach ($instance->getApiInstances() as $api) {
                $this->apiRunner->addApi($api);
            }
        } catch (Throwable $e) {
            $this->emit(self::ON_ERROR, [$e]);
            return false;
        }

        $this->workerInstances[] = $instance;
        return true;
    }

    #[ApiMethod]
    public function shutdown(): void
    {
        $this->emit(self::ON_SHUTDOWN_REQUESTED);
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
        if (empty($this->workerInstances)) {
            return;
        }
        $futures = [];
        foreach ($this->workerInstances as $instance) {
            $futures[] = async($instance->stop(...));
        }
        try {
            [$errors, $results] = awaitAll($futures);
            foreach ($errors as $e) {
                $this->logger->error('Error on stopping worker instance: ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to stop worker instance: ' . $e->getMessage());
        }
    }
}
