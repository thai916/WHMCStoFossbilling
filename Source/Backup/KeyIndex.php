<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source\Backup;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

/**
 * Fixed-width, lexicographically sortable (key, row offset) records.
 *
 * Keys are canonical positive decimal strings up to 30 digits. Offsets are
 * decimal byte offsets up to 20 digits. Zero padding makes bytewise string
 * comparison equivalent to numeric comparison without bcmath in the sorter.
 */
final class KeyIndex
{
    public const KEY_WIDTH = 30;
    public const OFFSET_WIDTH = 20;
    public const RECORD = self::KEY_WIDTH + self::OFFSET_WIDTH;

    public static function encode(string $key, int $offset): string
    {
        if (!ctype_digit($key) || strlen($key) > self::KEY_WIDTH || strlen((string) $offset) > self::OFFSET_WIDTH || $offset < 0) throw new SafeException('Prepared SQL key index entry is out of range.');
        return str_pad($key, self::KEY_WIDTH, '0', STR_PAD_LEFT) . str_pad((string) $offset, self::OFFSET_WIDTH, '0', STR_PAD_LEFT);
    }

    public static function key(string $record): string
    {
        $key = ltrim(substr($record, 0, self::KEY_WIDTH), '0');
        return $key === '' ? '0' : $key;
    }

    public static function offset(string $record): int
    {
        $offset = ltrim(substr($record, self::KEY_WIDTH, self::OFFSET_WIDTH), '0');
        return $offset === '' ? 0 : (int) $offset;
    }

    public static function compare(string $left, string $right): int
    {
        return strcmp(substr($left, 0, self::KEY_WIDTH), substr($right, 0, self::KEY_WIDTH));
    }
}
