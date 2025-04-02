<?php

namespace IMEdge\Node\Rpc\Api;

use IMEdge\CertificateStore\CaStore\CaStoreDirectory;
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
    // Alternative: Application::PROCESS_NAME . '::CA'
    public const DEFAULT_CA_NAME = 'IMEdge CA';
    public const DEFAULT_CA_DIR = 'CA';

    public function __construct(
        protected NodeRunner $node,
        protected ?CertificationAuthority $ca,
        protected LoggerInterface $logger
    ) {
    }

    #[ApiMethod()]
    public function getCaCertificate(): string
    {
        return $this->ca()->getCertificate()->toPEM()->string();
    }

    #[ApiMethod()]
    public function sign(string $csr, ?string $token = null): string
    {
        return $this->ca()->sign(CertificationRequest::fromPEM(PEM::fromString($csr)))->toPEM()->string();
    }

    #[ApiMethod()]
    #[ApiRole('administrator')]
    public function signPendingCsr(RpcPeerType $peerType, string $csr): string
    {
        return $this->ca->sign(CertificationRequest::fromPEM(PEM::fromString($csr)))->toPEM()->string();
    }

    protected function ca(): CertificationAuthority
    {
        return $this->ca ??= new CertificationAuthority(
            self::DEFAULT_CA_NAME,
            new CaStoreDirectory($this->node->getConfigDir() . '/' . self::DEFAULT_CA_DIR)
        );
    }
}
