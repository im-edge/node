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

class SignCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct()
    {
        parent::__construct('sign', $this->handle(...));
        $this->setDescription('Sign a given certificate');
        $this->addOptions([
            Option::create(null, 'csr', GetOpt::REQUIRED_ARGUMENT)
                ->setDescription('Certificate Signing Request, e.g. --csr "--- BEGIN ..."'),
        ]);
    }

    public function handle(GetOpt $options): void
    {
        $csr = $options->getOption('csr');
        if ($csr === null) {
            throw new Missing("Option 'csr' is required");
        }
        $rpc = new SimpleClient(NodeRunner::SOCKET_FILE, $this->logger);
        try {
            if ($cert = $rpc->request('ca.sign', [$csr])) {
                echo "$cert\n";
            }
            EventLoop::queue(fn () => exit(0));
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            EventLoop::queue(fn () => exit(1));
        }
    }
}
