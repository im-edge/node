<?php

namespace IMEdge\Node\Monitoring;

use Amp\Redis\RedisClient;
use gipfl\Json\JsonString;
use gipfl\LinuxHealth\Memory;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Metrics\Ci;
use IMEdge\Metrics\Measurement;
use IMEdge\Metrics\Metric;
use IMEdge\Metrics\MetricDatatype;
use IMEdge\Node\Services;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use RuntimeException;

class InternalMetricsCollection
{
    protected const PROCESS_NAME = 'IMEdge/Daemon';
    protected const INTERVAL = 5;
    protected float $lastUsr;
    protected float $lastSys;
    protected RedisClient $redis;
    protected string $uuidString;
    protected ?string $timer = null;

    public function __construct(
        protected NodeIdentifier $nodeIdentifier,
        Services $services,
        protected LoggerInterface $logger
    ) {
        $this->uuidString = $this->nodeIdentifier->uuid->toString();
        $this->redis = $services->getRedisClient('IMEdge/internalMetrics');
        $this->logger->notice('Redis connection for internal DataNode metrics is ready');
    }

    public function start(): void
    {
        if ($this->timer) {
            throw new RuntimeException('Cannot start internal metrics collection twice');
        }
        [$this->lastUsr, $this->lastSys] = self::getCpuSeconds();
        $this->timer = EventLoop::repeat(self::INTERVAL, $this->emitMeasurements(...));
    }

    public function stop(): void
    {
        if ($this->timer) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
    }

    protected function emitMeasurements(): void
    {
        $now = time();
        // TODO: CPU/Memory for sub-processes
        $this->shipMeasurement($this->prepareMeasurementCpu($now));
        $this->shipMeasurement($this->prepareMeasurementMemory($now));
        $this->shipMeasurement(new Measurement(new Ci($this->uuidString, 'EventLoop'), $now, [
            new Metric('registeredCallbacks', count(EventLoop::getDriver()->getIdentifiers()))
        ]));
    }

    protected function prepareMeasurementCpu($now): Measurement
    {
        [$currentUsr, $currentSys] = self::getCpuSeconds();
        $percentUsed = ($currentUsr - $this->lastUsr + $currentSys - $this->lastSys) / self::INTERVAL;
        $this->lastSys = $currentSys;
        $this->lastUsr = $currentUsr;
        $ci = new Ci($this->uuidString, 'UsedCPU', self::PROCESS_NAME);
        return new Measurement($ci, $now, [
            new Metric('user', $currentUsr, MetricDatatype::COUNTER),
            new Metric('system', $currentSys, MetricDatatype::COUNTER),
            new Metric('percent', $percentUsed),
        ]);
    }

    protected function prepareMeasurementMemory($now): Measurement
    {
        $ci = new Ci($this->uuidString, 'UsedMemory', self::PROCESS_NAME);
        $memory = Memory::getUsageForPid(getmypid());
        return new Measurement($ci, $now, [
            new Metric('size', $memory->size),
            new Metric('rss', $memory->rss),
            new Metric('shared', $memory->shared),
        ]);
    }

    protected function shipScenarioMeasurement(Measurement $measurement): void
    {
        EventLoop::queue(function () use ($measurement) {
            $this->redis->execute(
                'XADD',
                'internalMetrics',
                'MAXLEN',
                '~',
                10_000,
                '*',
                'measurement',
                JsonString::encode($measurement)
            );
        });
    }

    /**
     * @return array{0: float, 1: float} User, Sys
     */
    protected static function getCpuSeconds(): array
    {
        $usage = getrusage();
        return [
            ($usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000),
            ($usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000),
        ];
    }
}
