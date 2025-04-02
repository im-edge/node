<?php

namespace IMEdge\Node;

use IMEdge\Config\Settings;
use IMEdge\Inventory\NodeIdentifier;
use Psr\Log\LoggerInterface;

interface ImedgeWorker
{
    public function __construct(
        Settings $settings,
        NodeIdentifier $nodeIdentifier,
        LoggerInterface $logger
    );
    public function start(): void;
    public function stop(): void;
    public function getApiInstances(): array;
}
