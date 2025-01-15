<?php

namespace IMEdge\Node\Rpc\UnixSocket;

use IMEdge\Json\JsonSerialization;
use stdClass;

class UnixSocketPeer implements JsonSerialization
{
    final public function __construct(
        public readonly int $pid,
        public readonly int $uid,
        public readonly int $gid,
        public readonly string $username,
        public readonly ?string $fullName,
        public readonly string $groupName
    ) {
    }

    public static function fromSerialization($any): UnixSocketPeer
    {
        return new static($any->pid, $any->uid, $any->gid, $any->username, $any->fullName, $any->groupName);
    }

    public function jsonSerialize(): stdClass
    {
        return (object) [
            'pid' => $this->pid,
            'uid' => $this->uid,
            'gid' => $this->gid,
            'username'  => $this->username,
            'fullName'  => $this->fullName,
            'groupName' => $this->groupName,
        ];
    }
}
