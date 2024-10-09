<?php

namespace IMEdge\Node;

class ApplicationContext
{
    protected const DEFAULT_SOCKET_PATH = '/run/imedge';
    protected const DEFAULT_SOCKET_FILE = self::DEFAULT_SOCKET_PATH . '/node.sock';
    protected const ENV_SOCKET_FILE = 'IMEDGE_SOCKET';
    protected static ?string $socketPath = null;

    public static function getControlSocketPath(): string
    {
        if (self::$socketPath === null) {
            self::$socketPath = $_ENV[self::ENV_SOCKET_FILE]
                ?? $_SERVER[self::ENV_SOCKET_FILE]
                ?? self::DEFAULT_SOCKET_FILE;
        }

        return self::$socketPath;
    }
}
