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
            return \Box\Mod\Whmcsmigrator\Source\ConnectionDiagnostics::message($error);
        }

        return 'Operation failed safely. No source values are included in this diagnostic.';
    }
}
