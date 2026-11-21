<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Security;

final class Sensitive
{
    public static function field(string $name): bool
    {
        return (bool) preg_match('/pass|secret|token|salt|hash|crypt|auth|epp|cvv|cvc|card|bankacct|bankaccount|routing|iban|securityquestion|securityanswer|accesskey|api_?key|private_?key|session|recovery|twofactor|configoption[0-9]+|^(?:module)?config(?:uration|options?)?$|^settings$/i', $name);
    }

    public static function table(string $suffix): bool
    {
        return (bool) preg_match('/gateway|configuration|session|oauth|token|auth|password|security|credential|paymethod|paymentmethod|card|bankaccount|twofactor|moduleconfiguration/i', $suffix);
    }

    /** Never include source values in errors or ordinary report downloads. */
    public static function error(\Throwable $error): string
    {
        if ($error instanceof SafeException) {
            return $error->getMessage();
        }
        if ($error instanceof \PDOException) {
            return match ((int) ($error->errorInfo[1] ?? $error->getCode())) {
                1045 => 'Database authentication failed. Check the dedicated account credentials.',
                1049 => 'The selected database does not exist or is inaccessible.',
                1044, 1142 => 'Database privileges are insufficient.',
                2002, 2003 => 'Database connection failed. Check hostname, port and firewall allowlisting.',
                2006, 2013 => 'Database connection was interrupted. This operation may be retried.',
                2026 => 'TLS negotiation failed. Check the CA certificate and server identity.',
                default => 'Database operation failed. Credentials and SQL parameters have been withheld.',
            };
        }

        return 'Operation failed safely. No source values are included in this diagnostic.';
    }
}
