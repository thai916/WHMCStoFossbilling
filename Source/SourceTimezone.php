<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Source;

use Box\Mod\Whmcsmigrator\Security\SafeException;

final class SourceTimezone
{
    // Narrow, non-secret candidates only. A matching key is not proof that the
    // remote PHP runtime actually uses it (configuration.php/hooks can override).
    public const SETTINGS = ['Timezone', 'TimeZone', 'timezone', 'DefaultTimezone', 'DefaultTimeZone', 'SystemTimezone'];

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
                if (preg_match('/\\Atimestamp\\b/i', $schema[$name]['columns'][$field]['type'] ?? '')) {
                    throw new SafeException('A native historical field uses MySQL TIMESTAMP storage, which is session-converted rather than an application-local DATETIME. Use archive mode pending a reviewed storage-specific timezone adapter; no application timezone was incorrectly applied.');
                }
            }
        }
    }

    public static function detect(array $settings, array $database): array
    {
        $candidates = [];
        foreach ($settings as $row) {
            if (in_array($row['setting'] ?? null, self::SETTINGS, true) && self::iana($row['value'] ?? null)) {
                $candidates[(string) $row['setting']] = $row['value'];
            }
        }
        $unique = array_values(array_unique(array_values($candidates)));
        $seconds = isset($database['offset_seconds']) && is_numeric($database['offset_seconds']) ? (int) $database['offset_seconds'] : null;
        $offset = $seconds === null ? null : sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
        if ($seconds !== null && abs($seconds) % 60 !== 0) $offset .= sprintf(':%02d', abs($seconds) % 60);

        return [
            'status' => 'unverified', 'timezone' => null,
            'detected_timezone' => count($unique) === 1 && count($settings) <= 16 ? $unique[0] : null,
            'method' => $candidates ? 'application_configuration_candidate' : 'database_observation_only',
            'confidence' => $candidates ? 'candidate_only' : 'insufficient',
            'automatic_inconclusive' => true,
            'application_candidates' => $candidates,
            'database' => [
                'session_timezone' => self::databaseZone($database['session_timezone'] ?? null),
                'server_timezone' => self::databaseZone($database['server_timezone'] ?? null),
                'session_utc_offset' => $offset, 'session_utc_offset_seconds' => $seconds,
            ],
            'php_runtime_timezone' => 'unavailable_via_database',
            'warning' => 'MySQL timezone/offset is not the WHMCS application timezone. Database configuration candidates cannot prove the remote PHP/configuration.php or hook behavior. Verify the application timezone before timestamp imports; current settings also cannot prove historical timezone changes.',
        ];
    }

    public static function override(array $detection, string $timezone): array
    {
        if (empty($detection['automatic_inconclusive'])) throw new SafeException('A verified automatic application timezone cannot be overridden by a database-timezone guess.');
        if (!self::iana($timezone)) throw new SafeException('Confirm an exact IANA application timezone. A UTC offset alone cannot establish daylight-saving history.');
        $detection['timezone'] = $timezone;
        $detection['status'] = 'verified';
        $detection['confidence'] = 'administrator_verified';
        $detection['method'] = 'administrator_verified_application_timezone';

        return $detection;
    }

    private static function iana(mixed $value): bool
    {
        return is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }

    private static function databaseZone(mixed $value): ?string
    {
        return is_string($value) && ($value === 'SYSTEM' || self::iana($value) || preg_match('/\\A[+-]\\d{2}:\\d{2}\\z/D', $value)) ? $value : null;
    }
}
