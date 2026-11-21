<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Source\GrantPolicy;

$usage = "GRANT USAGE ON *.* TO 'fixture_reader'@'198.51.100.27'";
$accepted = [
    ['GRANT SELECT ON `account_db`.* TO `reader`@`localhost`', 'account_db', [], 'MySQL backtick database grant'],
    ['GRANT SELECT ON `account\\_db`.* TO `reader`@`198.51.100.27`', 'account_db', ['partial_revokes' => false], 'cPanel escaped underscore and host-specific account'],
    ['GRANT SELECT ON `account\\_db\\_whmcs`.* TO \'reader\'@\'192.0.2.%\'', 'account_db_whmcs', ['partial_revokes' => false], 'cPanel multiple escaped underscores and quoted host pattern'],
    ["GRANT SELECT ON account_db.* TO 'reader'@'%'", 'account_db', [], 'Unquoted database and MySQL quoted account'],
    [" \tgrant\nselect ON `account_db` . * TO 'reader'@'2001:db8::1'; \n", 'account_db', [], 'Whitespace/case/semicolon and IPv6 account host'],
    ['GRANT SELECT ON "account_db".* TO "reader"@"db-client.example.invalid"', 'account_db', [], 'ANSI-quoted database/account'],
    ['GRANT SELECT ON `account``db`.* TO `reader`@`%`', 'account`db', [], 'Doubled backtick preserves the literal identifier instead of stripping it'],
    ['GRANT SELECT ON `account"db`.* TO `reader`@`%`', 'account"db', [], 'Literal quote inside a backtick identifier'],
    ['GRANT SELECT ON `account\\%db`.* TO `reader`@`%`', 'account%db', ['partial_revokes' => false], 'Escaped percent is literal, not a wildcard'],
    ["GRANT SELECT ON `account_db`.* TO 'WITH GRANT OPTION'@'%' REQUIRE SSL", 'account_db', [], 'Grant-option-looking quoted username is not delegation'],
    ["GRANT SELECT ON `account_db`.* TO 'reader'@'%' IDENTIFIED BY PASSWORD '*NEVER_EXPOSE_HASH' REQUIRE SSL WITH MAX_USER_CONNECTIONS 3", 'account_db', [], 'MariaDB authentication/resource suffix is not a privilege'],
    ["GRANT SELECT ON `account_db`.* TO 'reader'@'%' IDENTIFIED VIA mysql_native_password USING 'WITH GRANT OPTION' OR unix_socket", 'account_db', [], 'MariaDB multi-plugin authentication strings are not parsed as grant options'],
    ['GRANT SELECT ON `account\\_%`.* TO `reader`@`%`', 'account_db', ['partial_revokes' => false], 'Known MySQL/MariaDB wildcard semantics cover the whole selected database'],
    ['GRANT SELECT ON `ACCOUNT_DB`.* TO `reader`@`%`', 'account_db', ['case_insensitive' => true], 'Case-insensitive server identifier rules'],
    ['GRANT SELECT ON `account_db`.* TO `reader`@`%`', 'account_db', ['partial_revokes' => true], 'MySQL partial-revokes literal database identifier'],
    ['GRANT SELECT ON `account\\_db`.* TO `reader`@`%`', 'account\\_db', ['partial_revokes' => true], 'Partial-revokes mode preserves literal stored backslashes'],
];
foreach ($accepted as [$grant, $database, $context, $label]) {
    $result = GrantPolicy::verify([$usage, $grant], $database, $context);
    expect($result['status'] === 'verified' && $result['scope'] === 'database-wide SELECT', $label);
    $safe = json_encode($result, JSON_THROW_ON_ERROR);
    expect(!str_contains($safe, 'NEVER_EXPOSE') && !str_contains($safe, 'fixture_reader') && !str_contains($safe, '198.51.100.27'), 'Scope diagnostics exclude account/host/authentication data: ' . $label);
}
$full = 'GRANT SELECT ON `account_db`.* TO `reader`@`%`';
$combined = GrantPolicy::verify([$full, 'GRANT SELECT (`id`, `name`) ON `account_db`.`tblclients` TO `reader`@`%`'], 'account_db');
expect(in_array('column SELECT', $combined['detected_scopes'], true), 'Column-limited SELECT can coexist with a complete database SELECT without hiding its actual scope');
$failure = static function (array $grants, string $expected, string $label, array $context = []): void {
    try {
        GrantPolicy::verify($grants, 'account_db', $context);
        expect(false, $label);
    } catch (SafeException $error) {
        expect(str_contains($error->getMessage(), $expected) && !str_contains($error->getMessage(), 'NEVER_EXPOSE'), $label);
    }
};
foreach (['INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'EXECUTE', 'FILE', 'PROCESS', 'SUPER', 'ALL PRIVILEGES', 'CREATE TEMPORARY TABLES', 'TRIGGER', 'EVENT', 'BACKUP_ADMIN'] as $privilege) {
    $failure([$full, "GRANT $privilege ON `other_db`.* TO 'reader'@'%'"], 'privileges beyond SELECT', $privilege . ' on any database is still rejected');
}
$failure([$full . ' WITH GRANT OPTION'], 'read-only protection', 'Delegation rights are rejected');
$failure([$full, "GRANT UPDATE (`name`) ON `account_db`.`tblclients` TO 'reader'@'%'"], 'read-only protection', 'Column-level write privilege is rejected even alongside full database SELECT');
$failure(["GRANT SELECT ON *.* TO 'reader'@'%'"], 'global SELECT', 'Global SELECT is rejected, never required');
$failure(["GRANT SELECT ON `account_db`.`tblclients` TO 'reader'@'%'"], 'Insufficient privileges', 'Table-only SELECT is insufficient, not an inspection failure');
$failure(["GRANT SELECT (`id`, `name`) ON `account_db`.`tblclients` TO 'reader'@'%'"], 'column SELECT', 'Column-only SELECT failure reports detected scope');
$failure([$usage], 'Insufficient privileges', 'USAGE alone establishes missing database SELECT');
$failure(["GRANT SELECT ON `different_db`.* TO 'reader'@'%'"], 'Insufficient privileges', 'A different database SELECT does not establish source coverage');
$failure([$full, "GRANT `read_role` TO 'reader'@'%'"], 'Unable to verify privileges', 'Roles remain inconclusive even when direct database SELECT exists');
$failure([$full, "REVOKE SELECT ON `account_db`.* FROM 'reader'@'%'"], 'Unable to verify privileges', 'Partial revoke/restriction output is never ignored');
$failure([], 'Unable to verify privileges', 'Empty grant output is unavailable evidence, not proof of missing SELECT');
$failure(['not valid SQL NEVER_EXPOSE'], 'Unable to verify privileges', 'Unknown output fails closed without echoing raw grant text');
$failure(["GRANT SELECT ON `account\\_%`.* TO 'reader'@'%'"], 'Unable to verify privileges', 'Unknown wildcard/partial-revoke semantics are inconclusive');
$failure(["GRANT SELECT ON `account\\_%`.* TO 'reader'@'%'"], 'Insufficient privileges', 'Known literal partial-revoke semantics do not match a wildcard pattern', ['partial_revokes' => true]);
$failure(["GRANT SELECT ON `account\\_db`.* TO 'reader'@'%'"], 'Insufficient privileges', 'Partial-revokes mode does not incorrectly strip literal stored backslashes', ['partial_revokes' => true]);
$failure(["GRANT SELECT ON `account\\_db`.* TO 'reader'@'%'"], 'Unable to verify privileges', 'Escaped grants need known pattern semantics rather than guessing when server policy is hidden');
$failure(["GRANT SELECT ON `%`.* TO 'reader'@'%'"], 'Unable to verify privileges', 'All-database wildcard cannot bypass global-SELECT isolation', ['partial_revokes' => false]);
$failure(["GRANT SELECT ON `ACCOUNT_DB`.* TO 'reader'@'%'"], 'Unable to verify privileges', 'Unknown server identifier-case rules do not prove a missing grant');
$failure(["GRANT SELECT ON `ACCOUNT_DB`.* TO 'reader'@'%'"], 'Insufficient privileges', 'Known case-sensitive server rules reject a different identifier', ['case_insensitive' => false]);
$failure(["GRANT SELECT ON `account_db.* TO 'reader'@'%'"], 'Unable to verify privileges', 'Unclosed identifier quote fails closed');
$failure(["GRANT SELECT (`id`,) ON `account_db`.`tblclients` TO 'reader'@'%'"], 'Unable to verify privileges', 'Malformed column list is an inspection failure rather than evidence of a missing grant');
$failure(["GRANT SELECT ON `account_db`.* TO 'reader'@'%' unexpected_tokens"], 'Unable to verify privileges', 'Unexpected account suffix is not silently ignored');
$failure(["GRANT SELECT (`id`) ON `account_db`.* TO 'reader'@'%'"], 'Unable to verify privileges', 'Invalid column-limited database-wide syntax is not mistaken for coverage');

final class GrantFixtureStatement extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
final class GrantFixturePdo extends PDO
{
    public array $queries = [];
    public function __construct(private Closure $response) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $this->queries[] = $query;

        return ($this->response)($query);
    }
}
$driverError = static function (int $code): never {
    $error = new PDOException('NEVER_EXPOSE driver user/hash/password detail');
    $error->errorInfo = ['42000', $code, 'NEVER_EXPOSE'];
    throw $error;
};
$metadata = [['Variable_name' => 'partial_revokes', 'Value' => 'OFF'], ['Variable_name' => 'lower_case_table_names', 'Value' => '0']];
$pdo = new GrantFixturePdo(static fn (string $query) => new GrantFixtureStatement(str_starts_with($query, 'SHOW GRANTS') ? [$full] : $metadata));
$result = GrantPolicy::inspect($pdo, 'account_db', '8.0.31');
expect($pdo->queries[0] === 'SHOW GRANTS FOR CURRENT_USER()' && $result['inspection_method'] === $pdo->queries[0], 'Parenthesized CURRENT_USER() is the preferred identity-safe inspection query');
$pdo = new GrantFixturePdo(static function (string $query) use ($full, $metadata, $driverError): GrantFixtureStatement {
    if ($query === 'SHOW GRANTS FOR CURRENT_USER()') $driverError(1064);

    return new GrantFixtureStatement(str_starts_with($query, 'SHOW GRANTS') ? [$full] : $metadata);
});
$result = GrantPolicy::inspect($pdo, 'account_db', '10.5.0-MariaDB');
expect($result['inspection_method'] === 'SHOW GRANTS FOR CURRENT_USER', 'Only unsupported CURRENT_USER() syntax triggers the compatible no-parentheses fallback');
$pdo = new GrantFixturePdo(static function (string $query) use ($full, $metadata, $driverError): GrantFixtureStatement {
    if (str_starts_with($query, 'SHOW GRANTS FOR')) $driverError(1235);

    return new GrantFixtureStatement($query === 'SHOW GRANTS' ? [$full] : $metadata);
});
expect(GrantPolicy::inspect($pdo, 'account_db')['inspection_method'] === 'SHOW GRANTS', 'Bare SHOW GRANTS safely means the current authenticated account when explicit syntax is unsupported');
$pdo = new GrantFixturePdo(static fn (string $query) => $driverError(1142));
try {
    GrantPolicy::inspect($pdo, 'account_db');
    expect(false, 'Grant inspection denied must fail');
} catch (SafeException $error) {
    expect(count($pdo->queries) === 1 && str_contains($error->getMessage(), 'Unable to verify privileges') && !str_contains($error->getMessage(), 'NEVER_EXPOSE'), 'Denied grant inspection is not retried or misreported as a missing SELECT grant');
}
$pdo = new GrantFixturePdo(static function (string $query) use ($full, $driverError): GrantFixtureStatement {
    if (str_starts_with($query, 'SHOW GLOBAL')) $driverError(1227);

    return new GrantFixtureStatement([$full]);
});
expect(GrantPolicy::inspect($pdo, 'account_db')['status'] === 'verified', 'Optional server-variable visibility is not required for an unambiguous literal database grant');
$pdo = new GrantFixturePdo(static fn (string $query) => $driverError(1064));
try {
    GrantPolicy::inspect($pdo, 'account_db');
    expect(false, 'Unsupported grant inspection must fail');
} catch (SafeException $error) {
    expect(count($pdo->queries) === 3 && str_contains($error->getMessage(), 'Unable to verify privileges'), 'Exhausted syntax fallbacks remain inconclusive, not missing permissions');
}
$pdo = new GrantFixturePdo(static function (string $query) use ($driverError): GrantFixtureStatement {
    if (str_starts_with($query, 'SHOW GLOBAL')) $driverError(1227);

    return new GrantFixtureStatement(["GRANT SELECT ON `account\\_%`.* TO 'reader'@'%'"]);
});
try {
    GrantPolicy::inspect($pdo, 'account_db', '8.0.31');
    expect(false, 'Unknown pattern semantics must fail');
} catch (SafeException $error) {
    expect(str_contains($error->getMessage(), 'Unable to verify privileges'), 'Denied optional metadata cannot convert ambiguous database wildcard coverage into a false missing-grant claim');
}
echo "Grant syntax and inspection suite finished. No database or network connection was used.\n";
