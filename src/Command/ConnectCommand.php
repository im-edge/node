<?php

namespace IMEdge\Node\Command;

use GetOpt\ArgumentException\Missing;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Option;
use IMEdge\Node\Application;
use IMEdge\Node\NodeRunner;
use IMEdge\Node\Rpc\SimpleClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Revolt\EventLoop;

class ConnectCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct()
    {
        parent::__construct('connect', $this->handle(...));
        $this->setDescription(sprintf(
            'Connect to a remote %s instance',
            Application::PROCESS_NAME
        ));
        $this->addOptions([
            Option::create(null, 'to', GetOpt::REQUIRED_ARGUMENT)
                ->setDescription('Connect to, e.g. --to 192.0.2.100:5669'),
            Option::create(null, 'persist')
                ->setDescription('Persist connection, will be re-established at every start'),
        ]);
    }


    public function handle(GetOpt $options): void
    {
        $to = $options->getOption('to');
        if ($to === null) {
            throw new Missing("Option 'to' is required");
        }
        $this->logger->debug('Connecting to ' . $to);
        $rpc = new SimpleClient(NodeRunner::SOCKET_FILE, $this->logger);
        try {
            $result = $rpc->request('node.connect', [$to, (bool) $options->getOption('persist')]);
            // TODO: distinct connected, already connected, connection pending...
            $this->logger->notice('Connected');
            EventLoop::queue(fn () => exit(0));
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            EventLoop::queue(fn () => exit(1));
        }
    }

    protected function establishConnection()
    {
    }
}
