<?php

namespace IMEdge\Node\Network;

enum ConnectionState: string
{
    case PENDING = 'pending';
    case FAILING = 'failing';
    case CONNECTED = 'connected';
    case CONNECTING = 'connecting';
}
