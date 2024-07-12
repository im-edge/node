<?php

namespace IMEdge\Node\Inventory;

use IMEdge\Inventory\CentralInventory;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\JsonRpc\JsonRpcConnection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

class RemoteInventory implements CentralInventory
{
    public function __construct(
        protected readonly JsonRpcConnection $connection,
        protected readonly UuidInterface $targetUuid,
        protected readonly array $tableSyncPositions,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function shipBulkActions(array $actions): void
    {
        try {
            $this->connection->request('remoteInventory.shipBulkActions', (object) [
                'actions' => $actions
            ]);
            $this->logger->notice(count($actions) . ' ACTIONS HAVE been shipped');
        } catch (\Exception $e) {
            $this->logger->notice(sprintf(
                'Failed to ship %d bulk actions: %s',
                count($actions),
                $e->getMessage()
            ));
        }
    }

    public function getCredentials(): array
    {
        // TODO: Implement getCredentials() method.
        return [];
    }

    public function loadTableSyncPositions(NodeIdentifier $nodeIdentifier): array
    {
        return $this->tableSyncPositions;
    }
}
