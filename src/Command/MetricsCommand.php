<?php

namespace IMEdge\Node\Command;

use GetOpt\ArgumentException\Missing;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Option;
use IMEdge\InfluxDbStreamer\InfluxDb\InfluxDbWriterV1;
use IMEdge\InfluxDbStreamer\InfluxDbStreamer;
use IMEdge\Log\ProcessLogger;
use IMEdge\Node\Application;
use IMEdge\Node\ApplicationContext;
use IMEdge\SimpleDaemon\Process;
use Revolt\EventLoop;

class MetricsCommand extends Command
{
    public function __construct()
    {
        parent::__construct('metrics', [$this, 'handle']);
        $this->setDescription(sprintf('Stream internal metrics for %s', Application::PROCESS_NAME));
        $this->addOptions([
            Option::create(null, 'baseUrl', GetOpt::REQUIRED_ARGUMENT)
                ->setDescription('Connect to, e.g. --url http://192.0.2.100:8086'),
            Option::create(null, 'database', GetOpt::REQUIRED_ARGUMENT)
                ->setDescription('InfluxDB database name'),
            Option::create(null, 'apiVersion', GetOpt::REQUIRED_ARGUMENT)
                ->setDescription('InfluxDB API version name, e.g. --apiVersion v1. Currently, only v1 is supported'),
            Option::create(null, 'username', GetOpt::OPTIONAL_ARGUMENT)
                ->setDescription('InfluxDB username/organization'),
            Option::create(null, 'password', GetOpt::OPTIONAL_ARGUMENT)
                ->setDescription('InfluxDB password/token'),
        ]);
    }

    public function handle(GetOpt $options): void
    {
        Process::setTitle(Application::PROCESS_NAME . '-MetricShipper');
        $home = ApplicationContext::getHomeDirectory();
        $baseUrl = $options->getOption('baseUrl');
        if ($baseUrl === null) {
            throw new Missing("Option 'baseUrl' is required");
        }
        $dbName = $options->getOption('database');
        if ($dbName === null) {
            throw new Missing("Option 'database' is required");
        }
        $apiVersion = $options->getOption('apiVersion');
        if ($apiVersion === null) {
            throw new Missing("Option 'apiVersion' is required");
        }
        if ($apiVersion !== 'v1') {
            throw new Missing("v1 is the only supported API version 1");
        }
        $writer = new InfluxDbWriterV1(
            $baseUrl,
            $dbName,
            $options->getOption('username'),
            $options->getOption('password'),
        );
        $logger = ProcessLogger::create(Application::LOG_NAME, $options);
        Application::checkRequirements($logger);
        $writer = new InfluxDbStreamer("$home/redis/redis.sock", $writer, $logger);
        EventLoop::queue($writer->run(...));
        EventLoop::run();
    }
}
