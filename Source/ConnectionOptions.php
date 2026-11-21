<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Source;

use Box\Mod\Whmcsmigrator\Security\SafeException;

final class ConnectionOptions
{
    public static function assertPlan(#[\SensitiveParameter] array $options, array $plan): void
    {
        $validated = self::validate($options, true);
        $expected = $plan['source_security_mode'] ?? (
            in_array(strtolower($validated['host']), ['localhost', '127.0.0.1', '::1'], true) ? $validated['security_mode'] : 'verified_tls'
        );
        if ($validated['security_mode'] !== $expected) throw new SafeException('Source connection security changed after this run was authorized. Create a newly reviewed run; its security cannot silently change.');
    }

    public static function validate(#[\SensitiveParameter] array $input, bool $legacyStoredProfile = false): array
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
        $mode = (string) ($input['security_mode'] ?? (array_key_exists('tls', $input) ? (!empty($input['tls']) ? 'verified_tls' : 'standard') : 'standard'));
        if (!in_array($mode, ['verified_tls', 'unverified_tls', 'standard'], true)) throw new SafeException('Unknown source connection security mode.');
        $legacyLocal = $legacyStoredProfile && !array_key_exists('security_mode', $input) && array_key_exists('tls', $input)
            && empty($input['tls']) && in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
        $confirmed = filter_var($input['security_acknowledged'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($mode !== 'verified_tls' && !$confirmed && !$legacyLocal) {
            throw new SafeException('Explicitly acknowledge the risks before using unverified TLS or a standard connection without TLS. No automatic security downgrade is allowed.');
        }
        $tls = $mode !== 'standard';
        $ca = trim((string) ($input['tls_ca'] ?? ''));
        if ($mode === 'verified_tls' && $ca === '') $ca = CaBundle::defaultPath();
        if ($mode === 'verified_tls' && !CaBundle::readable($ca)) {
            throw new SafeException('The CA path is not a readable local file. Clear it to use the included default bundle, or provide your database provider’s CA certificate path on the FOSSBilling server.');
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
            'security_mode' => $mode, 'security_acknowledged' => $confirmed || $legacyLocal,
            'source_timezone' => $timezone,
        ];
    }
}
