<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Destination;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

/**
 * Opt-in destructive reset of the business tables checked before an authorized
 * migration. Never changes table structures or reads the source database.
 */
final class Overwrite
{
    public const CONFIRMATION = 'OVERWRITE FOSSBILLING DATA';
    public const TABLES = [
        'client', 'product', 'product_payment', 'client_order', 'service_hosting',
        'service_hosting_hp', 'service_hosting_server', 'service_domain', 'invoice',
        'invoice_item', 'transaction', 'client_balance', 'support_ticket',
        'support_ticket_message', 'support_ticket_note', 'activity_client_email',
        'email_queue'
    ];
    private const ENTITY_TABLES = [
        'client', 'product', 'product_payment', 'client_order', 'service_hosting',
        'service_hosting_hp', 'service_hosting_server', 'service_domain', 'invoice',
        'invoice_item', 'transaction', 'client_balance', 'support_ticket',
        'support_ticket_message', 'support_ticket_note', 'activity_client_email'
    ];
    private const DELETE_ORDER = [
        'support_ticket_message', 'support_ticket_note', 'support_ticket',
        'activity_client_email', 'invoice_item', 'transaction', 'invoice',
        'client_balance', 'service_domain', 'service_hosting', 'service_hosting_hp',
        'service_hosting_server', 'client_order', 'product_payment', 'product',
        'client', 'email_queue'
    ];

    /** Counts shown before the administrator accepts permanent deletion. */
    public static function inspect(Store $store, ?int $source): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $total = (int) ($store->one("SELECT COUNT(*) AS n FROM `$table`")['n'] ?? 0);
            $owned = 0;
            if ($table !== 'email_queue') {
                $parameters = [$table];
                $scope = '';
                if ($source !== null) { $scope = ' AND source_id=?'; $parameters[] = $source; }
                $owned = (int) ($store->one("SELECT COUNT(DISTINCT d.id) AS n FROM `$table` d JOIN (SELECT DISTINCT destination_id FROM whmcstofossbilling_identity WHERE destination_entity=?$scope) m ON m.destination_id=d.id", $parameters)['n'] ?? 0);
            }
            $tables[$table] = ['total' => $total, 'owned' => $owned, 'unowned' => $total - $owned];
        }

        return $tables;
    }

    /** Delete every blocking row and stale ownership mapping inside the caller's transaction. */
    public static function apply(Store $store): array
    {
        if (!$store->pdo->inTransaction()) throw new SafeException('A destination transaction is required before overwriting records.');
        $deleted = [];
        foreach (self::DELETE_ORDER as $table) {
            $deleted[$table] = $store->execute("DELETE FROM `$table`");
        }
        $placeholders = implode(',', array_fill(0, count(self::ENTITY_TABLES), '?'));
        $deleted['whmcstofossbilling_identity'] = $store->execute('DELETE FROM whmcstofossbilling_identity WHERE destination_entity IN (' . $placeholders . ')', self::ENTITY_TABLES);
        $deleted['unfinished_runs_cancelled'] = $store->execute("UPDATE whmcstofossbilling_run SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE mode<>'dry_run' AND status IN ('running','paused')");

        return $deleted;
    }
}
