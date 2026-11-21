<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

final class ConnectionFactory
{
    public static function attributes(#[\SensitiveParameter] array $options): array
    {
        $attributes = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => $options['timeout'],
            \PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            \PDO::MYSQL_ATTR_LOCAL_INFILE => false,
        ];
        if ($options['security_mode'] !== 'standard') {
            // mysqlnd needs an SSL attribute to request encryption. Turning off
            // verification alone does NOT request or prove a TLS connection.
            $attributes[\PDO::MYSQL_ATTR_SSL_CA] = $options['security_mode'] === 'verified_tls' ? $options['tls_ca'] : CaBundle::defaultPath();
            $attributes[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $options['security_mode'] === 'verified_tls';
        }

        return $attributes;
    }

    public static function connect(#[\SensitiveParameter] array $options): \PDO
    {
        if ($options['security_mode'] !== 'standard') self::requireTlsHandshake($options);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if (count($warnings) < 8) $warnings[] = substr($message, 0, 2048);

            return true;
        }, E_WARNING);
        try {
            return new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $options['host'], $options['port'], $options['database']), $options['username'], $options['password'], self::attributes($options));
        } catch (\PDOException $error) {
            throw new SafeException(ConnectionDiagnostics::message($error, $warnings));
        } finally {
            restore_error_handler();
        }
    }

    public static function status(\PDO $pdo, string $mode): array
    {
        $row = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch();
        $encrypted = !empty($row['Value']);
        if ($mode !== 'standard' && !$encrypted) throw new SafeException('The source connection did not negotiate TLS. Access refused; no unencrypted fallback was authorized.');

        return ['successful' => true, 'security_mode' => $mode, 'tls_active' => $encrypted, 'server_identity_verified' => $encrypted && $mode === 'verified_tls'];
    }

    private static function requireTlsHandshake(#[\SensitiveParameter] array $options): void
    {
        // Inspect only the greeting, before sending any username/password.
        // mysqlnd versions may otherwise accept a server without CLIENT_SSL.
        $host = str_contains($options['host'], ':') ? '[' . $options['host'] . ']' : $options['host'];
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool { $warnings[] = $message; return true; }, E_WARNING);
        try {
            $socket = stream_socket_client('tcp://' . $host . ':' . $options['port'], $errno, $error, $options['timeout']);
        } finally {
            restore_error_handler();
        }
        if ($socket === false) throw new SafeException(ConnectionDiagnostics::message(new \RuntimeException($error, $errno), $warnings));
        $deadline = microtime(true) + $options['timeout'];
        $remaining = static function () use ($socket, $deadline): void {
            $seconds = $deadline - microtime(true);
            if ($seconds <= 0) throw new SafeException('Database TLS greeting/handshake timed out. No credentials were sent and no security downgrade was attempted.');
            stream_set_timeout($socket, (int) $seconds, (int) (($seconds - (int) $seconds) * 1000000));
        };
        try {
            $read = static function (int $length) use ($socket, $remaining): string {
                $data = '';
                while (strlen($data) < $length) {
                    $remaining();
                    $chunk = fread($socket, $length - strlen($data));
                    if ($chunk === false || $chunk === '') throw new SafeException('Database greeting timed out or the connection closed before authentication. No credentials were sent.');
                    $data .= $chunk;
                }

                return $data;
            };
            $header = $read(4);
            $length = unpack('V', substr($header, 0, 3) . "\0")[1];
            if ($length < 1 || $length > 16384) throw new SafeException('Invalid MySQL greeting. No credentials were sent.');
            $greeting = $read($length);
            if (ord($greeting[0]) === 255) throw new SafeException('MySQL rejected the connection before authentication. Check host allowlisting and connection limits.');
            $end = strpos($greeting, "\0", 1);
            $offset = ($end === false ? 0 : $end) + 1 + 4 + 8 + 1;
            if (ord($greeting[0]) !== 10 || $end === false || strlen($greeting) < $offset + 2) throw new SafeException('Unsupported MySQL greeting. No credentials were sent.');
            if (!(unpack('v', substr($greeting, $offset, 2))[1] & 0x800)) throw new SafeException('TLS negotiation refused: the server does not advertise TLS. No credentials were sent and no standard-connection fallback was attempted.');
            // Complete a credential-free TLS handshake too: mysqlnd can collapse
            // certificate errors into generic error 2002, hiding the real cause.
            $verified = $options['security_mode'] === 'verified_tls';
            $ssl = ['verify_peer' => $verified, 'verify_peer_name' => $verified, 'peer_name' => $options['host']];
            if ($verified) $ssl['cafile'] = $options['tls_ca'];
            foreach ($ssl as $name => $value) stream_context_set_option($socket, 'ssl', $name, $value);
            $request = pack('VVC', 0x200 | 0x800 | 0x8000, 16777216, 45) . str_repeat("\0", 23);
            $packet = substr(pack('V', strlen($request)), 0, 3) . "\1" . $request;
            $remaining();
            if (fwrite($socket, $packet) !== strlen($packet)) throw new SafeException('TLS request could not be sent. No credentials were sent.');
            $remaining();
            $warnings = [];
            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool { $warnings[] = $message; return true; }, E_WARNING);
            try {
                $encrypted = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            } finally {
                restore_error_handler();
            }
            if ($encrypted !== true) throw new SafeException(ConnectionDiagnostics::message(new \RuntimeException('TLS handshake failed'), $warnings));
        } finally {
            fclose($socket);
        }
    }
}
