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

class CertificateCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct()
    {
        parent::__construct('certificate', $this->handle(...));
        $this->setDescription('Set my signed certificate');
        $this->addOptions([
            Option::create(null, 'cert', GetOpt::REQUIRED_ARGUMENT)
                ->setDescription('Signed certificate, e.g. --cert "--- BEGIN ..."'),
        ]);
    }

    public function handle(GetOpt $options): void
    {
        $rpc = new SimpleClient(NodeRunner::SOCKET_FILE, $this->logger);
        $cert = $options->getOption('cert');
        if ($cert === null) {
            throw new Missing("Option 'cert' is required");
        }
        try {
            if ($rpc->request('node.setSignedCertificate', [$cert])) {
                $this->logger->notice('Certificate has been stored');
            }
            EventLoop::queue(fn () => exit(0));
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            EventLoop::queue(fn () => exit(1));
        }
    }
}
