<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

final class ReaderFactory
{
    public static function open(array $options): Reader|DumpReader
    {
        return ($options['kind'] ?? '') === 'sql_file' ? new DumpReader($options) : new Reader($options);
    }
    public static function assertPlan(array $options, array $plan): void
    {
        if (($options['kind'] ?? '') !== 'sql_file') { ConnectionOptions::assertPlan($options, $plan); return; }
        if (($plan['source_security_mode'] ?? '') !== 'sql_file' || ($plan['source_backup_id'] ?? '') !== $options['backup_id']) throw new SafeException('The SQL backup differs from the authorized run. Review a new run.');
    }
}
