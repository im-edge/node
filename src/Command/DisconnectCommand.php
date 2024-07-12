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

class DisconnectCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct()
    {
        parent::__construct('disconnect', [$this, 'handle']);
        $this->setDescription(sprintf(
            'Disconnect from a remote %s instance',
            Application::PROCESS_NAME
        ));
        $this->addOptions([
            Option::create(null, 'from', GetOpt::REQUIRED_ARGUMENT)
                ->setDescription('Socket used to connect, e.g. --from 192.0.2.100:5669'),
            Option::create(null, 'persist')
                ->setDescription('Persist disconnection, so that it will NOT be re-established at the next start'),
        ]);
    }

    public function handle(GetOpt $options): void
    {
        $from = $options->getOption('from');
        if ($from === null) {
            throw new Missing("Option 'from' is required");
        }
        $this->logger->debug('Disconnecting from ' . $from);
        $rpc = new SimpleClient(NodeRunner::SOCKET_FILE, $this->logger);
        try {
            $rpc->request('node.disconnect', [$from, (bool) $options->getOption('persist')]);
            // TODO: distinct connected, already connected, connection pending... should we really wait? Parameter?
            EventLoop::queue(fn () => exit(0));
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            EventLoop::queue(fn () => exit(1));
        }
    }
}
