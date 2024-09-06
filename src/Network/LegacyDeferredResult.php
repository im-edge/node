<?php

namespace IMEdge\Node\Network;

use Exception;
use React\Promise\Deferred;
use React\Promise\Promise;
use Revolt\EventLoop;

/**
 * @deprecated
 */
class LegacyDeferredResult
{
    public static function success(mixed $result): Promise
    {
        $deferred = new Deferred();
        EventLoop::queue(function () use ($deferred, $result) {
            $deferred->resolve($result);
        });

        return $deferred->promise();
    }

    public static function fail(Exception $error): Promise
    {
        $deferred = new Deferred();
        EventLoop::queue(function () use ($deferred, $error) {
            $deferred->reject($error);
        });

        return $deferred->promise();
    }
}
