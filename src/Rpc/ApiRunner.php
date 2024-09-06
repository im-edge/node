<?php

namespace IMEdge\Node\Rpc;

use IMEdge\JsonRpc\Error;
use IMEdge\JsonRpc\ErrorCode;
use IMEdge\JsonRpc\Notification;
use IMEdge\JsonRpc\Request;
use IMEdge\JsonRpc\RequestHandler;
use IMEdge\JsonRpc\Response;
use IMEdge\Node\Rpc\Routing\NodeRouter;
use IMEdge\RpcApi\Hydrator;
use IMEdge\RpcApi\Reflection\MetaDataClass;
use IMEdge\RpcApi\Reflection\MetaDataMethod;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

class ApiRunner implements RequestHandler
{
    protected const NAMESPACE_SEPARATOR = '.';
    protected const NO_SUCH_TARGET = -32004;

    /** @var MetaDataMethod[] */
    protected array $methodMeta = [];
    /** @var \Closure[] */
    protected array $methods = [];

    protected array $knownMethods = [];

    public function __construct(
        protected string $identifier,
        protected ?NodeRouter $nodeRouter = null,
        protected ?LoggerInterface $logger = null
    ) {
        Hydrator::registerType(UuidInterface::class, static function ($value) {
            return Uuid::fromString($value);
        });
    }

    /**
     * @return array<string, MetaDataMethod>
     */
    public function getKnownMethods(): array
    {
        return $this->knownMethods;
    }

    public function addApi(object $instance): void
    {
        $meta = MetaDataClass::analyze(get_class($instance));
        if (! $meta) {
            throw new RuntimeException("Failed to analyze API instance: " . get_class($instance));
        }
        $namespace = $meta->namespace;
        foreach ($meta->methods as $method) {
            $key = $namespace . self::NAMESPACE_SEPARATOR . $method->name;
            $this->methodMeta[$key] = $method;
            $this->methods[$key] = $instance->{$method->name}(...);
            $this->knownMethods[$namespace . self::NAMESPACE_SEPARATOR . $method->name] = $method;
        }
    }

    public function handleRequest(Request $request): Response
    {
        if (
            $this->nodeRouter
            && ($target = $request->getExtraProperty('target'))
            && ($target !== $this->identifier)
        ) {
            return $this->handleRemoteRequest($request, $target);
        }

        $meta = $this->methodMeta[$request->method] ?? null;
        if ($meta === null) {
            return new Response($request->id, null, new Error(ErrorCode::METHOD_NOT_FOUND));
        }

        if ($request->params === null) {
            return new Response($request->id, $this->methods[$request->method]());
        }

        $parameters = (array) $request->params;
        foreach ($parameters as $key => &$value) {
            if ($type = $meta->getParameter($key)) {
                try {
                    $value = Hydrator::hydrate($type->type, $value);
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException(sprintf('%s:%s: %s', $meta->name, $key, $e->getMessage()));
                }
            }
        }

        return new Response($request->id, $this->methods[$request->method](...$parameters));
    }

    public function handleNotification(Notification $notification): void
    {
        if (
            $this->nodeRouter
            && ($target = $notification->getExtraProperty('target'))
            && ($target !== $this->identifier)
        ) {
            $this->handleNewRemoteNotification($notification, $target);
            return;
        }

        $meta = $this->methodMeta[$notification->method] ?? null;
        if ($meta === null) {
            // TODO: Log missing method?
            return;
        }

        if ($notification->params === null) {
            $this->methods[$notification->method]();
            return;
        }

        $parameters = (array) $notification->params;
        foreach ($parameters as $key => &$value) {
            if ($type = $meta->getParameter($key)) {
                $value = Hydrator::hydrate($type->type, $value);
            }
        }

        $this->methods[$notification->method](...$parameters);
    }

    protected function handleRemoteRequest(Request $request, $target): Response
    {
        if ($rpc = $this->nodeRouter->getConnectionFor(Uuid::fromString($target))) {
            return $rpc->sendRequest($request);
        }

        if (!empty($this->nodeRouter->directlyConnected->getNodes())) {
            return new Response($request->id, null, new Error(self::NO_SUCH_TARGET, sprintf(
                'I am not %s. Connections: %s',
                $target,
                implode(', ', array_keys($this->nodeRouter->directlyConnected->jsonSerialize())) // TODO: list uuids
            )));
        }

        return new Response($request->id, null, new Error(self::NO_SUCH_TARGET, sprintf(
            'I am not %s and not connected to other nodes',
            $target
        )));
    }

    protected function handleNewRemoteNotification(Notification $notification, $target): void
    {
        if ($rpc = $this->nodeRouter->getConnectionFor($target)) {
            $rpc->sendPacket($notification);
        }
        // TODO: else log lost notification?
    }
}
