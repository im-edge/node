<?php

namespace IMEdge\Node\Command;

use GetOpt\Command;
use GetOpt\GetOpt;
use IMEdge\Log\ProcessLogger;
use IMEdge\Node\Application;
use IMEdge\Node\ApplicationContext;
use IMEdge\Node\NodeRunner;
use IMEdge\SimpleDaemon\SimpleDaemon;

class DaemonCommand extends Command
{
    public function __construct()
    {
        parent::__construct('daemon', [$this, 'handle']);
        $this->setDescription(sprintf('Run the %s daemon', Application::PROCESS_NAME));
    }

    public function handle(GetOpt $options): void
    {
        $home = ApplicationContext::getHomeDirectory();
        $logger = ProcessLogger::create(Application::LOG_NAME, $options);
        Application::checkRequirements($logger);

        $daemon = new SimpleDaemon();
        $daemon->setLogger($logger);
        $daemon->attachTask(new NodeRunner($home, $logger));
        $daemon->run();
    }
}
