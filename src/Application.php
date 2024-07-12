<?php

namespace IMEdge\Node;

use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;

class Application
{
    public const LOG_NAME = 'imedge-node';
    public const PROCESS_NAME = 'IMEdgeNode';

    public static function checkRequirements(LoggerInterface $logger): void
    {
        static::emitPlatformWarnings($logger);
    }

    public static function emitPlatformWarnings(LoggerInterface $logger): void
    {
        if (EventLoop::getDriver() instanceof StreamSelectDriver) {
            $logger->warning('Please install php-event or php-ev for better performance');
        }
        if (PHP_ZTS) {
            $logger->warning(
                'This PHP binary has been compiled with ZTS (Zend Thread Safety).'
                . ' This could cause issues, as methods like chdir() will not be passed to the operating system'
            );
        }
    }
}
