<?php

namespace IMEdge\Node\Worker;

use Amp\Process\Process as AmpProcess;
use IMEdge\Config\Settings;
use IMEdge\JsonRpc\JsonRpcConnection;
use IMEdge\Node\Rpc\Api\LogApi;
use IMEdge\Node\Rpc\ApiRunner;
use IMEdge\ProcessRunner\BufferedLineReader;
use IMEdge\Protocol\NetString\NetStringConnection;
use IMEdge\SimpleDaemon\Process;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Revolt\EventLoop;

use function Amp\ByteStream\pipe;

class WorkerInstance
{
    protected AmpProcess $process;
    public JsonRpcConnection $jsonRpc;
    protected ApiRunner $handler;

    public function __construct(
        public readonly string $name,
        public readonly UuidInterface $uuid,
        protected LoggerInterface $logger
    ) {
        $this->handler = new ApiRunner($this->uuid->toString());
        $this->handler->addApi(new LogApi($this->logger, "[$name (worker)] "));
    }

    public function run(string $workerClassName, ?Settings $settings): void
    {
        $this->jsonRpc->request('worker.launch', [
            $workerClassName,
            $settings
        ]);
    }

    public function start(): void
    {
        $cmd = dirname(Process::getBinaryPath()) . '/imedge-worker';
        $this->process = $process = AmpProcess::start($cmd);
        $netString = new NetStringConnection($process->getStdout(), $process->getStdin());
        $this->jsonRpc = new JsonRpcConnection($netString, $netString, $this->handler, $this->logger);
        $stdErrReader = new BufferedLineReader($this->logger->error(...), "\n");
        EventLoop::queue(fn () => pipe($process->getStderr(), $stdErrReader));
    }

    public function stop(): void
    {
        $this->process->kill();
    }
}
