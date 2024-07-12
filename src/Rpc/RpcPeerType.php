<?php

namespace IMEdge\Node\Rpc;

enum RpcPeerType
{
    case ANONYMOUS;
    case PEER;
    case CONTROL;
}
