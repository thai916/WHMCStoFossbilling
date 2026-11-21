<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Mapping;

use Box\Mod\Whmcsmigrator\Security\SafeException;

final class EventDate
{
    public static function utc(mixed $value, ?string $timezone): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) throw new SafeException('Historical date must be a string.');
        // Business calendar dates are not instants and must retain their original day.
        if (strlen($value) === 10) return \Box\Mod\Whmcsmigrator\Destination\NativeWriter::date($value);
        if (!$timezone || !in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new SafeException('Confirm the source IANA timezone before importing historical event timestamps.');
        }
        $zone = new \DateTimeZone($timezone);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) throw new SafeException('Invalid or nonexistent historical local timestamp.');
        $wall = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))->getTimestamp();
        $transitions = $zone->getTransitions($date->getTimestamp() - 86400, $date->getTimestamp() + 86400);
        $previous = null;
        foreach ($transitions ?: [] as $transition) {
            if ($previous !== null && $transition['offset'] < $previous) {
                if ($wall >= $transition['ts'] + $transition['offset'] && $wall < $transition['ts'] + $previous) {
                    throw new SafeException('Historical timestamp falls in an ambiguous daylight-saving hour. Explicit offset reconciliation is required.');
                }
            }
            $previous = $transition['offset'];
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
