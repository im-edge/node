<?php

namespace IMEdge\Node\UtilityClasses;

use gipfl\DataType\Settings;
use gipfl\Json\JsonString;
use IMEdge\Filesystem\Directory;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

use function file_put_contents;
use function in_array;
use function method_exists;

trait DirectoryBasedComponent
{
    protected LoggerInterface $logger;
    protected string $baseDir;
    protected ?Settings $config = null;
    protected ?string $name = null;
    protected ?UuidInterface $uuid = null;

    public function __construct(string $baseDir, LoggerInterface $logger)
    {
        $this->baseDir = $baseDir;
        $this->logger = $logger;
        if (method_exists($this, 'construct')) {
            $this->construct();
        }
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function run(): void
    {
        $config = $this->requireConfig();
        $this->name = $config->get('name') ?: $this->generateName();
        $this->uuid = Uuid::fromString($config->getRequired('uuid'));
        $this->config = $config;
        if (method_exists($this, 'initialize')) {
            $this->initialize();
        }
    }

    public function requireBeingConfigured(): void
    {
        $config = $this->config ?? $this->readOptionalConfig();
        if ($config === null) {
            throw new RuntimeException(sprintf(
                'There is no configured %s in %s',
                $this->getMyShortClassName(),
                $this->getBaseDir()
            ));
        }
        if ($this->uuid === null) {
            $this->uuid = Uuid::fromString($config->getRequired('uuid'));
        }
        $this->name = $config->getRequired('name');
        $this->config = $config;
    }

    public function requireConfig(): Settings
    {
        return $this->config = $this->readOptionalConfig() ?: $this->initializeNewNode();
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function getUuid(): UuidInterface
    {
        if ($this->uuid === null) {
            $this->requireBeingConfigured();
        }

        return $this->uuid;
    }

    protected function getMyShortClassName(): string
    {
        return substr(strrchr(get_class($this), '\\'), 1);
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        if ($this->config) {
            $this->config->set('name', $name);
            $this->storeConfig($this->config);
        }
    }

    public function getName(): string
    {
        if ($this->name === null) {
            if (method_exists($this, 'generateName')) {
                $this->name = $this->generateName();
            } else {
                throw new RuntimeException('Name is required');
            }
        }

        return $this->name;
    }

    protected function readOptionalConfig(): ?Settings
    {
        $configFile = $this->getConfigDir() . '/' . $this->getConfigFileName();
        if (@file_exists($configFile) && @is_readable($configFile)) {
            $config = Settings::fromSerialization(JsonString::decode(file_get_contents($configFile)));
            $this->validateConfig($config);
            return $config;
        }

        return null;
    }

    protected function validateConfig(Settings $config): void
    {
        if ($config->getRequired('config-type') !== $this->getConfigType()) {
            throw new RuntimeException(sprintf(
                '"%s" config type expected, got "%s"',
                $this->getConfigType(),
                $config->getRequired('config-type')
            ));
        }
        if (! $this->supportsConfigVersion($config->getRequired('config-version'))) {
            throw new RuntimeException(sprintf(
                'Unsupported config version: %s',
                $config->getRequired('config-version')
            ));
        }
    }

    protected function initializeNewNode(): Settings
    {
        Directory::requireWritable($this->baseDir, true);
        $settings = $this->generateNewSettings();
        $this->storeConfig($settings);

        return $settings;
    }

    public function getConfigDir(): string
    {
        return $this->baseDir . '/' . self::DOT_DIR;
    }

    /**
     * Hint: this is not atomic
     */
    public function storeConfig(Settings $settings): void
    {
        $configDir = $this->getConfigDir();
        Directory::requireWritable($configDir);
        file_put_contents(
            $this->getConfigDir() . '/' . $this->getConfigFileName(),
            JsonString::encode($settings, JSON_PRETTY_PRINT) . "\n"
        );
    }

    protected function getConfigFileName(): string
    {
        return self::CONFIG_FILE_NAME;
    }

    protected function getConfigType(): string
    {
        return self::CONFIG_TYPE;
    }

    protected function getConfigVersion(): string
    {
        return self::CONFIG_VERSION;
    }

    protected function getSupportedConfigVersions(): array
    {
        return self::SUPPORTED_CONFIG_VERSIONS;
    }

    protected function supportsConfigVersion(string $version): string
    {
        return in_array($version, $this->getSupportedConfigVersions(), true);
    }

    protected function generateNewSettings(): Settings
    {
        $settings = new Settings();
        $settings->set('config-type', $this->getConfigType());
        $settings->set('config-version', $this->getConfigVersion());
        $settings->set('uuid', Uuid::uuid4()->toString());
        $settings->set('name', $this->getName());

        return $settings;
    }
}
