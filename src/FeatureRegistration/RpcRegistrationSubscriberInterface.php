<?php

namespace IMEdge\Node\FeatureRegistration;

interface RpcRegistrationSubscriberInterface
{
    public function registerRpcNamespace(string $namespace, object $handler): void;
}
