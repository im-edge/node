<?php

namespace IMEdge\Node\Command;

use GetOpt\ArgumentException\Missing;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Option;
use IMEdge\Node\Application;
use IMEdge\Node\ApplicationContext;
use IMEdge\Node\Rpc\SimpleClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class RpcCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct()
    {
        parent::__construct('rpc', $this->handle(...));
        $this->setDescription(sprintf(
            'Run an RPC command, locally or on a remote %s instance',
            Application::PROCESS_NAME
        ));
        $this->addOptions([
            Option::create(null, 'command', GetOpt::REQUIRED_ARGUMENT)
                ->setDescription('Persist connection, will be re-established at every start'),
            Option::create(null, 'target', GetOpt::OPTIONAL_ARGUMENT)
                ->setDescription('Specify another target node by UUID'),
        ]);
    }

    public function handle(GetOpt $options): void
    {
        $command = $options->getOption('command');
        if ($command === null) {
            throw new Missing("Option 'command' is required");
        }
        $rpc = new SimpleClient(ApplicationContext::getControlSocketPath(), $this->logger);
        if ($target = $options->getOption('target')) {
            $rpc->setTarget($target);
        }
        $result = $rpc->request($command); // TODO: Params
        if (is_string($result)) {
            echo "$result\n";
        } elseif (is_bool($result)) {
            echo ($result ? '(true)' : '(false)') . "\n";
        } elseif ($result === null) {
            echo "(null)\n";
        } else {
            print_r($result);
        }
    }
}
