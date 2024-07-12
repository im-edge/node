<?php

namespace IMEdge\Node\UtilityClasses;

class Cli
{
    protected const PROXY_SETTINGS = [
        'http_proxy',
        'https_proxy',
        'HTTPS_PROXY',
        'ALL_PROXY',
    ];

    public static function clearProxySettings(): void
    {
        foreach (self::PROXY_SETTINGS as $setting) {
            putenv("$setting=");
        }
    }

    public static function writeError(string $message): void
    {
        file_put_contents('php://stderr', $message . PHP_EOL);
    }
}
