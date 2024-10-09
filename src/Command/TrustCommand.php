<?php

namespace IMEdge\Node\Command;

use GetOpt\ArgumentException\Missing;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Option;
use IMEdge\Node\ApplicationContext;
use IMEdge\Node\Rpc\SimpleClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Revolt\EventLoop;

class TrustCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct()
    {
        parent::__construct('trust', $this->handle(...));
        $this->setDescription('Trust a given CA certificate');
        $this->addOptions([
            Option::create(null, 'caCert', GetOpt::REQUIRED_ARGUMENT)
                ->setDescription('CA certificate, e.g. --caCert "--- BEGIN ..."'),
        ]);
    }

    public function handle(GetOpt $options): void
    {
        $cert = $options->getOption('caCert');
        if ($cert === null) {
            throw new Missing("Option 'caCert' is required");
        }
        $rpc = new SimpleClient(ApplicationContext::getControlSocketPath(), $this->logger);
        try {
            if ($rpc->request('node.addTrustedCa', [$cert])) {
                $this->logger->notice('Trust has been established');
            }
            EventLoop::queue(fn () => exit(0));
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            EventLoop::queue(fn () => exit(1));
        }
    }
}
