<?php

namespace IMEdge\Node\Rpc\Api;

use IMEdge\CertificateStore\CertificationAuthority;
use IMEdge\Node\NodeRunner;
use IMEdge\Node\Rpc\RpcPeerType;
use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;
use IMEdge\RpcApi\ApiRole;
use Psr\Log\LoggerInterface;
use Sop\CryptoEncoding\PEM;
use Sop\X509\CertificationRequest\CertificationRequest;

#[ApiNamespace('ca')]
class CaApi
{
    public function __construct(
        protected NodeRunner $node,
        protected CertificationAuthority $ca,
        protected LoggerInterface $logger
    ) {
    }

    #[ApiMethod()]
    public function getCaCertificate(): string
    {
        return $this->ca->getCertificate()->toPEM()->string();
    }

    #[ApiMethod()]
    public function sign(string $csr, ?string $token = null): string
    {
        return $this->ca->sign(CertificationRequest::fromPEM(PEM::fromString($csr)))->toPEM()->string();
    }

    #[ApiMethod()]
    #[ApiRole('administrator')]
    public function signPendingCsr(RpcPeerType $peerType, string $csr): string
    {
        return $this->ca->sign(CertificationRequest::fromPEM(PEM::fromString($csr)))->toPEM()->string();
    }
}
