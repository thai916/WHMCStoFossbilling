<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Destination;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

/**
 * Repairs only destination rows already owned by this module.
 *
 * Earlier 0.2 imports omitted two native visibility flags. This action changes
 * only `client_order.group_master` and `invoice.approved` on verified module-
 * owned records; it never re-imports, deletes, renumbers or sends a notification.
 */
final class VisibilityRepair
{
    public const CONFIRMATION = 'REPAIR IMPORTED VISIBILITY';

    public function __construct(private readonly Store $store)
    {
    }

    public function run(string $confirmation, array $scope = []): array
    {
        if (!hash_equals(self::CONFIRMATION, $confirmation)) {
            throw new SafeException('Type REPAIR IMPORTED VISIBILITY exactly before repairing imported visibility.');
        }
        $run = (string) ($scope['run_id'] ?? '');
        if ($run !== '' && !preg_match('/\A[a-f0-9]{32}\z/D', $run)) {
            throw new SafeException('Invalid run identifier.');
        }
        if ($run !== '') $this->store->run($run);
        $source = (int) ($scope['source_id'] ?? 0);
        if ($source < 0) throw new SafeException('Invalid source identifier.');

        $where = '';
        $values = [];
        if ($run !== '') { $where .= ' AND i.run_id=?'; $values[] = $run; }
        if ($source > 0) { $where .= ' AND i.source_id=?'; $values[] = $source; }

        $sources = $source > 0
            ? [$source]
            : array_map('intval', array_column($this->store->all("SELECT DISTINCT i.source_id FROM whmcstofossbilling_identity i WHERE i.destination_id IS NOT NULL AND i.destination_entity IN ('client_order','invoice')" . $where . ' ORDER BY i.source_id', $values), 'source_id'));
        $locks = [];
        try {
            foreach ($sources as $sourceId) {
                $lock = 'whmcstofossbilling-source-' . $sourceId;
                $this->store->lock($lock);
                $locks[] = $lock;
            }

            return $this->repair($where, $values, $run, $source);
        } finally {
            foreach (array_reverse($locks) as $lock) $this->store->unlock($lock);
        }
    }

    private function repair(string $where, array $values, string $run, int $source): array
    {
        $this->store->pdo->beginTransaction();
        try {
            $orders = $this->store->all("SELECT i.destination_id,i.source_id FROM whmcstofossbilling_identity i WHERE i.destination_entity='client_order' AND i.destination_id IS NOT NULL" . $where . ' AND EXISTS (SELECT 1 FROM client_order co WHERE co.id=i.destination_id AND co.service_type=\'hosting\' AND (co.group_master IS NULL OR co.group_master<>1)) ORDER BY i.id', $values);
            $invoices = $this->store->all("SELECT i.destination_id,i.source_id FROM whmcstofossbilling_identity i WHERE i.destination_entity='invoice' AND i.destination_id IS NOT NULL" . $where . ' AND EXISTS (SELECT 1 FROM invoice inv WHERE inv.id=i.destination_id AND inv.approved=0 AND inv.status IN (\'paid\',\'canceled\',\'refunded\')) ORDER BY i.id', $values);

            foreach ($orders as $order) {
                $id = (int) $order['destination_id'];
                $this->store->execute('UPDATE client_order SET group_master=1 WHERE id=?', [$id]);
                $this->refreshChecksum('client_order', $id, (int) $order['source_id']);
            }
            foreach ($invoices as $invoice) {
                $id = (int) $invoice['destination_id'];
                $this->store->execute('UPDATE invoice SET approved=1 WHERE id=?', [$id]);
                $this->refreshChecksum('invoice', $id, (int) $invoice['source_id']);
            }
            $this->store->pdo->commit();

            return [
                'orders' => count($orders),
                'invoices' => count($invoices),
                'changed' => count($orders) + count($invoices),
                'run_id' => $run,
                'source_id' => $source,
            ];
        } catch (\Throwable $error) {
            if ($this->store->pdo->inTransaction()) $this->store->pdo->rollBack();
            throw $error;
        }
    }

    private function refreshChecksum(string $entity, int $id, int $source): void
    {
        if (!in_array($entity, ['client_order', 'invoice'], true)) {
            throw new SafeException('Unrecognized repaired destination entity.');
        }
        $row = $this->store->one('SELECT * FROM `' . $entity . '` WHERE id=?', [$id]);
        if (!$row) throw new SafeException('A repaired record disappeared before its module-owned checksum could be refreshed.');
        $this->store->execute('UPDATE whmcstofossbilling_identity SET destination_checksum=? WHERE source_id=? AND destination_entity=? AND destination_id=?', [hash('sha256', Store::json($row)), $source, $entity, $id]);
    }
}
