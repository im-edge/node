<?php

namespace IMEdge\Node;

use DirectoryIterator;
use IMEdge\Config\Settings;
use IMEdge\Json\JsonString;
use RuntimeException;
use SplFileInfo;

class ApplicationContext
{
    protected const DEFAULT_SOCKET_PATH = '/run/imedge';
    protected const DEFAULT_SOCKET_FILE = self::DEFAULT_SOCKET_PATH . '/node.sock';
    protected const ENV_SOCKET_FILE = 'IMEDGE_SOCKET';
    protected static ?string $socketPath = null;
    protected static ?string $homeDirectory = null;

    public static function getControlSocketPath(): string
    {
        if (self::$socketPath === null) {
            self::$socketPath = $_ENV[self::ENV_SOCKET_FILE]
                ?? $_SERVER[self::ENV_SOCKET_FILE]
                ?? self::DEFAULT_SOCKET_FILE;
        }

        return self::$socketPath;
    }

    public static function getHomeDirectory(): string
    {
        return self::$homeDirectory ??= getenv('HOME');
    }

    public static function getConfigDirectory(): string
    {
        return self::getHomeDirectory() . '/.imedge';
    }

    public static function getConfigFile(): string
    {
        return self::getConfigDirectory() . '/node.json';
    }

    public static function getRedisSocket(): string
    {
        return 'unix://' . self::getHomeDirectory() . '/redis/redis.sock';
    }

    public static function requireSettings(): Settings
    {
        if ($settings = self::loadSettings()) {
            return $settings;
        }

        throw new RuntimeException('Failed to load settings from ' . self::getConfigFile());
    }

    public static function listEnabledModulesWithDirectory(): array
    {
        $directory = self::getEnabledFeaturesDirectory();
        if (!is_dir($directory) || !is_readable($directory)) {
            return [];
        }

        $modules = [];
        foreach (new DirectoryIterator($directory) as $fileInfo) {
            if ($fileInfo->isLink() && ctype_alnum($fileInfo->getBasename())) {
                $target = new SplFileInfo($fileInfo->getLinkTarget());
                if ($target->isDir() && $target->isReadable()) {
                    $modules[$fileInfo->getBasename()] = $target->getPathname();
                }
            }
        }

        return $modules;
    }

    public static function loadSettings(): ?Settings
    {
        $filename = self::getConfigFile();
        if (file_exists($filename) && is_readable($filename)){
            return Settings::fromSerialization(JsonString::decode(
                file_get_contents($filename)
            ));
        }

        return new Settings();
    }

    public static function getEnabledFeaturesDirectory(): string
    {
        return self::getConfigDirectory() . '/features-enabled';
    }

    public static function initializeFeatureAutoloaders(): void
    {
        $settings = self::requireSettings();
        foreach (self::listEnabledModulesWithDirectory() as $moduleName => $moduleDirectory) {
            $autoloadFile = "$moduleDirectory/vendor/autoload.php";
            if (file_exists($autoloadFile)) {
                require_once $autoloadFile;
            }
        }
    }
}
