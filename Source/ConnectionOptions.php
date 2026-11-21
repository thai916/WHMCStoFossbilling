<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Source;

use Box\Mod\Whmcsmigrator\Security\SafeException;

final class ConnectionOptions
{
    public static function validate(#[\SensitiveParameter] array $input): array
    {
        $host = trim((string) ($input['host'] ?? ''));
        if ($host === '' || str_contains($host, '://') || str_contains($host, ';') || strlen($host) > 253
            || (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            throw new SafeException('Enter a database hostname or IP address, not an HTTP URL.');
        }
        $port = filter_var($input['port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new SafeException('The database port must be between 1 and 65535.');
        }
        $database = (string) ($input['database'] ?? '');
        $username = (string) ($input['username'] ?? '');
        $prefix = (string) ($input['prefix'] ?? 'tbl');
        if (!preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/D', $database) || $username === '' || strlen($username) > 128 || preg_match('/[\x00-\x1f]/', $username)) {
            throw new SafeException('Database name or username is invalid.');
        }
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,31}\z/D', $prefix)) {
            throw new SafeException('The table prefix must be a short SQL identifier.');
        }
        $timeout = filter_var($input['timeout'] ?? 5, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if ($timeout === false) {
            throw new SafeException('Connection timeout must be between 1 and 10 seconds.');
        }
        $tls = !empty($input['tls']);
        $ca = trim((string) ($input['tls_ca'] ?? ''));
        if ($tls && $ca === '') $ca = CaBundle::defaultPath();
        if ($tls && !CaBundle::readable($ca)) {
            throw new SafeException('The CA path is not a readable local file. Clear it to use the included default bundle, or provide your database provider’s CA certificate path on the FOSSBilling server.');
        }
        if (!$tls && !in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            throw new SafeException('Verified TLS is required for a remote production database. Use a local tunnel endpoint if appropriate.');
        }
        $timezone = trim((string) ($input['source_timezone'] ?? ''));
        // New UI defaults are suggestions, not evidence about the source timezone.
        // Existing API callers explicitly supplying a verified timezone remain compatible.
        if (array_key_exists('source_timezone_verified', $input) && empty($input['source_timezone_verified'])) $timezone = '';
        if ($timezone !== '' && !in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new SafeException('Source timezone must be a verified IANA identifier, such as UTC or Asia/Kolkata.');
        }

        return [
            'host' => $host, 'port' => $port, 'database' => $database, 'username' => $username,
            'password' => (string) ($input['password'] ?? ''), 'prefix' => $prefix,
            'timeout' => $timeout, 'tls' => $tls, 'tls_ca' => $tls ? $ca : '',
            'source_timezone' => $timezone,
        ];
    }
}
