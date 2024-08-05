<?php

namespace IMEdge\Node\Command;

use GetOpt\ArgumentException\Missing;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Option;
use IMEdge\Node\NodeRunner;
use IMEdge\Node\Rpc\SimpleClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Revolt\EventLoop;

class ListenCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct()
    {
        parent::__construct('listen', $this->handle(...));
        $this->setDescription('Listen on a given TCP socket address');
        $this->addOptions([
            Option::create(null, 'socket', GetOpt::REQUIRED_ARGUMENT)
                ->setDescription('Listening socket, e.g. --socket 0.0.0.0:5660'),
            Option::create(null, 'persist')
                ->setDescription('Persist listener, will be enabled at every start'),
        ]);
    }

    public function handle(GetOpt $options): void
    {
        $socket = $options->getOption('socket');
        if ($socket === null) {
            throw new Missing("Option 'socket' is required");
        }
        $this->logger->debug('Listening on ' . $socket);
        $rpc = new SimpleClient(NodeRunner::SOCKET_FILE, $this->logger);
        try {
            if ($rpc->request('node.listen', [$socket, (bool) $options->getOption('persist')])) {
                $this->logger->notice("Listening on $socket");
            }
            EventLoop::queue(fn () => exit(0));
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            EventLoop::queue(fn () => exit(1));
        }
    }
}
