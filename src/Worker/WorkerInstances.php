<?php

namespace IMEdge\Node\Worker;

use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

use function Amp\async;
use function Amp\Future\awaitAll;

#[ApiNamespace('workers')]
class WorkerInstances
{
    /** @var array<string, WorkerInstance> */
    protected array $workers = [];
    /** @var array<string, string> */
    protected array $workerNames = [];

    public function __construct(protected LoggerInterface $logger)
    {
    }

    #[ApiMethod]
    public function launchWorker(string $name, ?UuidInterface $uuid): WorkerInstance
    {
        $uuid ??= Uuid::uuid4();
        $worker = new WorkerInstance($name, $uuid, $this->logger);
        $worker->start();
        $this->workers[$uuid->toString()] = $worker;
        $this->workerNames[$uuid->toString()] = $name;

        return $worker;
    }

    #[ApiMethod]
    public function listWorkers(): array
    {
        return $this->workerNames;
    }

    public function stop(): void
    {
        $futures = [];
        foreach ($this->workers as $worker) {
            $futures[] = async($worker->stop(...));
        }
        foreach (awaitAll($futures) as [$errors, $results]) {
            foreach ($errors as $error) {
                $this->logger->error('Error on stopping worker instance: ' . $error->getMessage());
            }
        }
        $this->workerNames = [];
        $this->workers = [];
    }
}
