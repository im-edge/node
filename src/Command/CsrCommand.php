<?php

namespace IMEdge\Node\Command;

use GetOpt\Command;
use GetOpt\GetOpt;
use IMEdge\Node\NodeRunner;
use IMEdge\Node\Rpc\SimpleClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Revolt\EventLoop;

class CsrCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct()
    {
        parent::__construct('csr', $this->handle(...));
        $this->setDescription('Show my Certificate Signing Request');
    }

    public function handle(GetOpt $options): void
    {
        $rpc = new SimpleClient(NodeRunner::SOCKET_FILE, $this->logger);
        try {
            echo $rpc->request('node.getCsr') . "\n";
            EventLoop::queue(fn () => exit(0));
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            EventLoop::queue(fn () => exit(1));
        }
    }
}
