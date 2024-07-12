<?php

namespace IMEdge\Node;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;

class Events implements EventEmitterInterface
{
    use EventEmitterTrait;

    public const INTERNAL_MEASUREMENT = 'internalMeasurement';
}
