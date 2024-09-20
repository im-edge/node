<?php

namespace IMEdge\Node\Network;

use IMEdge\Json\JsonSerialization;

class ConnectionInformation implements JsonSerialization
{
    public function __construct(
        public readonly string $peerAddress,
        public ?string $peerIdentifier = null,
        public ConnectionState $state = ConnectionState::PENDING,
        public ?string $errorMessage = null,
    ) {
    }

    public static function fromSerialization($any): ConnectionInformation
    {
        return new ConnectionInformation($any->peerAddress);
    }

    public function jsonSerialize(): object
    {
        $result = [
            'peerAddress' => $this->peerAddress,
            'state'       => $this->state,
        ];
        if ($this->peerIdentifier !== null) {
            $result['peerIdentifier'] = $this->peerIdentifier;
        }
        if ($this->errorMessage !== null) {
            $result['errorMessage'] = $this->errorMessage;
        }

        return (object) $result;
    }
}
