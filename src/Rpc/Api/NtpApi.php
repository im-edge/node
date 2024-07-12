<?php

namespace IMEdge\Node\Rpc\Api;

use IMEdge\Protocol\NTP\SNTP;
use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;

#[ApiNamespace('ntp')]
class NtpApi
{
    #[ApiMethod]
    public function request(array $servers = ['2.de.pool.ntp.org']): object
    {
        return SNTP::query($servers);
    }
}
