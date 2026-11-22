<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Destination;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

final class FreshCheck
{
    /** Reference defaults are allowed. Business rows must belong to this migration. */
    public static function check(Store $store, ?int $source = null): array
    {
        $unrelated = [];
        foreach (Overwrite::TABLES as $table) {
            if ($table === 'email_queue') continue;
            $parameters = [$table];
            $scope = '';
            if ($source !== null) { $scope = ' AND source_id=?'; $parameters[] = $source; }
            if ($store->one("SELECT 1 AS found FROM `$table` d LEFT JOIN (SELECT DISTINCT destination_id FROM whmcstofossbilling_identity WHERE destination_entity=?$scope) m ON m.destination_id=d.id WHERE m.destination_id IS NULL LIMIT 1", $parameters)) $unrelated[] = $table;
        }
        if ($store->one('SELECT 1 AS found FROM email_queue LIMIT 1')) $unrelated[] = 'email_queue';
        return ['ready' => !$unrelated, 'unrelated_tables' => $unrelated];
    }
    public static function assertReady(Store $store, ?int $source = null): void
    {
        $status = self::check($store, $source);
        if (!$status['ready']) throw new SafeException('Use a fresh FOSSBilling installation, resume the same migration, or explicitly confirm OVERWRITE FOSSBILLING DATA. Unrelated business records were found in: ' . implode(', ', $status['unrelated_tables']) . '. No records or table structures were changed.');
    }
}
