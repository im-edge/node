<?php

namespace IMEdge\Node\CertificateExtension;

use Sop\ASN1\Element;
use Sop\ASN1\Type\Primitive\ObjectIdentifier;
use Sop\X509\Certificate\Extension\Extension;

class NodeRpcAdminPermission extends Extension
{
    /**
     * @codingStandardsIgnoreStart -> PSR2.Methods.MethodDeclaration.Underscore
     */
    protected function _valueASN1(): Element
    {
        // @codingStandardsIgnoreEnd
        return new ObjectIdentifier('1.3.6.1.4.1.26840.5670.509.1');
    }
}
