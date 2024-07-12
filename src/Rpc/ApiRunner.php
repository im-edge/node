<?php

namespace IMEdge\Node\Rpc;

use gipfl\Protocol\JsonRpc\Request as LegacyRequest;
use IMEdge\JsonRpc\Error;
use IMEdge\JsonRpc\ErrorCode;
use IMEdge\JsonRpc\Notification;
use IMEdge\JsonRpc\Request;
use IMEdge\JsonRpc\RequestHandler;
use IMEdge\JsonRpc\Response;
use IMEdge\Node\Network\DataNodeConnections;
use IMEdge\Node\Rpc\Routing\NodeList;
use IMEdge\RpcApi\Hydrator;
use IMEdge\RpcApi\Reflection\MetaDataClass;
use IMEdge\RpcApi\Reflection\MetaDataMethod;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

use function React\Async\await as reactAwait;

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
        protected ?NodeList $nodeList = null,
        public readonly ?DataNodeConnections $dataNodeConnections = null,
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
            throw new \RuntimeException("Failed to analyze API instance: " . get_class($instance));
        }
        $namespace = $meta->namespace;
        foreach ($meta->methods as $method) {
            $key = $namespace . self::NAMESPACE_SEPARATOR . $method->name;
            $this->methodMeta[$key] = $method;
            $this->methods[$key] = $instance->{$method->name}(...);
            $this->knownMethods[$namespace . self::NAMESPACE_SEPARATOR . $method->name] = $method;
        }
    }

    protected function handleRemoteRequest(Request $request, $target): Response
    {
        if ($rpc = $this->dataNodeConnections->getOptionalConnection($target)) {
            return reactAwait($rpc->sendRequest(LegacyRequest::fromSerialization($request->jsonSerialize())));
        } else {
            if ($this->dataNodeConnections->hasConnections()) {
                return new Response($request->id, null, new Error(self::NO_SUCH_TARGET, sprintf(
                    'I am not %s. Connections: %s',
                    $target,
                    implode(', ', $this->dataNodeConnections->listActiveUuids())
                )));
            }

            return new Response($request->id, null, new Error(self::NO_SUCH_TARGET, sprintf(
                'I am not %s and not connected to other nodes',
                $target
            )));
        }
    }

    protected function handleNewRemoteRequest(Request $request, $target): Response
    {
        if ($rpc = $this->nodeList->getOptional($target)) {
            return $rpc->sendRequest($request);
        } else {
            if (!empty($this->nodeList->getNodes())) {
                return new Response($request->id, null, new Error(self::NO_SUCH_TARGET, sprintf(
                    'I am not %s. Connections: %s',
                    $target,
                    implode(', ', array_keys($this->nodeList->jsonSerialize())) // TODO: list uuids
                )));
            }

            return new Response($request->id, null, new Error(self::NO_SUCH_TARGET, sprintf(
                'I am not %s and not connected to other nodes',
                $target
            )));
        }
    }

    public function handleRequest(Request $request): Response
    {
        if (
            $this->nodeList
            && ($target = $request->getExtraProperty('target'))
            && ($target !== $this->identifier)
        ) {
            return $this->handleNewRemoteRequest($request, $target);
        }
        if (
            $this->dataNodeConnections
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
                $value = Hydrator::hydrate($type->type, $value);
            }
        }

        return new Response($request->id, $this->methods[$request->method](...$parameters));
    }

    public function handleNotification(Notification $notification): void
    {
        // TODO: Implement handleNotification() method.
    }
}
