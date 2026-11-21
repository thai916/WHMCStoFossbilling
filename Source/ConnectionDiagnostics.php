<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source;

/** Only fixed messages leave this classifier; never return the original error. */
final class ConnectionDiagnostics
{
    public static function message(\Throwable $error, array $warnings = []): string
    {
        $code = (int) ($error instanceof \PDOException ? ($error->errorInfo[1] ?? $error->getCode()) : $error->getCode());
        $text = strtolower($error->getMessage() . ' ' . implode(' ', $warnings));
        if ($code === 1045 || $code === 1698) return 'Database authentication failed. Check the dedicated username/password and whether the account is permitted from this outbound IP.';
        if ($code === 1049) return 'The selected database was not found. Check the complete hosting-account-prefixed database name.';
        if (in_array($code, [1044, 1142, 1143], true)) return 'Database access or SELECT permissions are missing. The dedicated account needs database-scoped SELECT-only grants.';
        if (preg_match('/certificate verify|certificate validation|peer certificate|certificate.*(?:match|expired|issuer)|self.signed|unable to get.*issuer/', $text)) {
            return 'TLS certificate validation failed. Check the CA chain and the hostname/IP named in the server certificate. No weaker connection was attempted.';
        }
        if ($code === 2026 || preg_match('/\\bssl\\b|\\btls\\b|handshake|crypto/', $text)) return 'TLS negotiation failed. Check server TLS support and protocol compatibility. No unencrypted fallback was attempted.';
        if (preg_match('/timed? out|timeout|10060|110\\)/', $text)) return 'Database connection timed out. A firewall, routing problem or unresponsive server may be responsible; these cannot always be distinguished remotely.';
        if (preg_match('/getaddrinfo|php_network_getaddresses|name or service not known|no such host|11001/', $text)) return 'Database hostname could not be resolved. Check the hostname and DNS.';
        if (preg_match('/network is unreachable|no route to host|10051|10065/', $text)) return 'Database host is unreachable from this FOSSBilling server. Check network routing or firewall rules.';
        if (preg_match('/refused|10061/', $text)) return 'Database port refused the connection. Check the MySQL port, listener and firewall allowlisting.';
        if (in_array($code, [2002, 2003], true)) return 'Database host/port could not be reached. Check hostname, port and firewall allowlisting; a blocked port and an unreachable host may look identical.';
        if (in_array($code, [2006, 2013], true)) return 'Database connection was interrupted. Refresh status before retrying.';

        return 'Database connection failed safely. Credentials and raw driver diagnostics have been withheld.';
    }
}
