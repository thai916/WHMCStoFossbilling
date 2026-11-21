<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Source;

use Box\Mod\Whmcsmigrator\Security\SafeException;

final class SourceTimezone
{
    public static function assertNativeStorage(array $schema, array $tables, string $target): void
    {
        if ($target === 'archive') return;
        $fields = [
            'clients' => ['datecreated'], 'tickets' => ['date', 'lastreply'],
            'ticketreplies' => ['date'], 'ticketnotes' => ['date'], 'emails' => ['date'],
        ];
        if ($target === 'historical_native') $fields += [
            'invoices' => ['date', 'datepaid', 'duedate'], 'accounts' => ['date'], 'credit' => ['date'],
            'hosting' => ['regdate', 'nextduedate', 'termination_date'],
        ];
        foreach ($tables as $name) {
            foreach ($fields[$schema[$name]['suffix']] ?? [] as $field) {
                if (preg_match('/\Atimestamp\b/i', $schema[$name]['columns'][$field]['type'] ?? '')) {
                    throw new SafeException('A native historical field uses MySQL TIMESTAMP storage, which is session-converted rather than an application-local DATETIME. Use archive mode pending a reviewed storage-specific timezone adapter; no application timezone was incorrectly applied.');
                }
            }
        }
    }

    /** Record the administrator's explicit WHMCS application timezone selection. */
    public static function selected(string $timezone): array
    {
        if (!self::iana($timezone)) {
            throw new SafeException('Select an exact IANA WHMCS application timezone. A UTC offset, MySQL setting or server locale is not sufficient.');
        }

        return [
            'status' => 'verified',
            'timezone' => $timezone,
            'detected_timezone' => null,
            'method' => 'administrator_selected_application_timezone',
            'confidence' => 'administrator_verified',
            'automatic_inconclusive' => false,
            'warning' => 'This timezone is saved only in the extension profile. Verify historical timezone changes before authorizing native event imports.',
        ];
    }

    /** No selection means timezone-sensitive native publication remains blocked. */
    public static function unselected(): array
    {
        return [
            'status' => 'unverified',
            'timezone' => null,
            'detected_timezone' => null,
            'method' => 'administrator_selection_required',
            'confidence' => 'none',
            'automatic_inconclusive' => false,
            'warning' => 'No WHMCS application timezone was selected. Native timestamp-sensitive imports remain blocked; date-only fields stay unchanged.',
        ];
    }

    private static function iana(mixed $value): bool
    {
        return is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }
}
