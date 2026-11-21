<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Source;

use Box\Mod\Whmcsmigrator\Security\SafeException;

/** Conservative: role-based grants and column-limited grants require DBA remediation. */
final class GrantPolicy
{
    public static function verify(array $grants, string $database): void
    {
        $schemaSelect = false;
        foreach ($grants as $grant) {
            if (!preg_match('/\AGRANT (.+?) ON (.+?) TO /i', $grant, $parts) || stripos($grant, 'WITH GRANT OPTION') !== false) {
                throw new SafeException('Cannot verify a direct SELECT-only account. Roles, proxy access and grant options are not accepted.');
            }
            $privileges = array_map('trim', explode(',', strtoupper($parts[1])));
            foreach ($privileges as $privilege) {
                if (!in_array($privilege, ['USAGE', 'SELECT'], true)) {
                    throw new SafeException('The source account has privileges beyond SELECT. Import refused; ask a DBA for a dedicated SELECT-only account.');
                }
            }
            $scope = str_replace('`', '', $parts[2]);
            if (in_array('SELECT', $privileges, true) && $scope === $database . '.*') {
                $schemaSelect = true;
            }
            if (in_array('SELECT', $privileges, true) && $scope === '*.*') {
                throw new SafeException('Global SELECT is unnecessarily broad. Grant SELECT only on the WHMCS database.');
            }
        }
        if (!$schemaSelect) {
            throw new SafeException('A direct database-wide SELECT grant is required; table/column grants cannot establish complete source coverage.');
        }
    }
}
