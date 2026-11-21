<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Source;

use Box\Mod\Whmcsmigrator\Security\SafeException;

final class CaBundle
{
    public static function defaultPath(): string
    {
        foreach ([ini_get('openssl.cafile'), ini_get('curl.cainfo'), dirname(__DIR__) . '/certificates/cacert.pem'] as $path) {
            if (is_string($path) && self::readable($path)) return (string) realpath($path);
        }

        throw new SafeException('No readable default CA bundle is installed. Restore certificates/cacert.pem in this extension or provide your database provider’s local CA certificate path.');
    }

    public static function readable(string $path): bool
    {
        return $path !== '' && !str_contains($path, "\0") && !str_contains($path, '://')
            && !str_starts_with($path, '\\\\') && !str_starts_with($path, '//')
            && is_file($path) && is_readable($path);
    }
}
