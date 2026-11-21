<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Box\Mod\Whmcsmigrator\Archive\Sanitizer;
use Box\Mod\Whmcsmigrator\Destination\NativeWriter;
use Box\Mod\Whmcsmigrator\Finance\Decimal;
use Box\Mod\Whmcsmigrator\Mapping\Profile;
use Box\Mod\Whmcsmigrator\Report\Exporter;
use Box\Mod\Whmcsmigrator\Security\Sensitive;
use Box\Mod\Whmcsmigrator\Security\Vault;
use Box\Mod\Whmcsmigrator\Source\ConnectionOptions;
use Box\Mod\Whmcsmigrator\Source\GrantPolicy;
use Box\Mod\Whmcsmigrator\Source\Reader;

$options = ['host' => '127.0.0.1', 'database' => 'whmcs_test', 'username' => 'reader', 'password' => 'test-only'];
expect(ConnectionOptions::validate($options)['port'] === 3306, 'Port defaults to 3306');
expect(ConnectionOptions::validate($options + ['prefix' => 'custom_'])['prefix'] === 'custom_', 'Custom identifier prefix accepted');
rejects(fn () => ConnectionOptions::validate(array_replace($options, ['host' => 'https://example.com'])), 'HTTP URLs rejected');
rejects(fn () => ConnectionOptions::validate(array_replace($options, ['host' => 'localhost;dbname=other'])), 'DSN injection rejected');
rejects(fn () => ConnectionOptions::validate($options + ['prefix' => 'tbl;DROP'] ), 'Identifier injection rejected');
rejects(fn () => ConnectionOptions::validate($options + ['port' => 0]), 'Invalid port rejected');
rejects(fn () => ConnectionOptions::validate($options + ['timeout' => 60]), 'Unbounded connection timeout rejected');
rejects(fn () => ConnectionOptions::validate(array_replace($options, ['host' => 'db.example.com'])), 'Remote unencrypted connection rejected');
rejects(fn () => ConnectionOptions::validate($options + ['tls' => true, 'tls_ca' => 'https://example.com/ca']), 'Remote certificate URL rejected');
$tlsOptions = ConnectionOptions::validate(array_replace($options, ['host' => 'db.example.com', 'tls' => true]));
expect(\Box\Mod\Whmcsmigrator\Source\CaBundle::readable($tlsOptions['tls_ca']), 'Remote TLS automatically uses a readable default CA bundle when no custom path is supplied');
$bundled = dirname(__DIR__) . '/certificates/cacert.pem';
$certificates = file_get_contents($bundled);
preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificates, $matches);
expect(count($matches[0]) > 100 && array_reduce($matches[0], static fn (bool $ok, string $pem): bool => $ok && openssl_x509_read($pem) !== false, true), 'Bundled trust store contains parseable real CA certificates, not a generated placeholder');
rejects(fn () => ConnectionOptions::validate($options + ['tls' => true, 'tls_ca' => 'D:/missing-whmcsmigrator-ca.pem']), 'Invalid explicit CA override is rejected instead of silently ignored');
rejects(fn () => ConnectionOptions::validate($options + ['tls' => true, 'tls_ca' => '\\\\server\\share\\ca.pem']), 'Network-share certificate path rejected');
expect(ConnectionOptions::validate($options + ['source_timezone' => 'UTC', 'source_timezone_verified' => false])['source_timezone'] === '', 'Suggested timezone cannot become verified source evidence without confirmation');
expect(ConnectionOptions::validate($options + ['source_timezone' => 'Asia/Kolkata', 'source_timezone_verified' => true])['source_timezone'] === 'Asia/Kolkata', 'Explicitly verified dropdown timezone is preserved');
expect(\Box\Mod\Whmcsmigrator\Source\PublicIp::parse("8.8.8.8\n") === '8.8.8.8', 'IP response parser accepts a public IPv4 address');
foreach (['127.0.0.1', '192.168.1.1', '::1', '<script>8.8.8.8</script>', 'not-an-ip'] as $invalidIp) {
    rejects(fn () => \Box\Mod\Whmcsmigrator\Source\PublicIp::parse($invalidIp), 'IP response parser rejects private/non-IPv4/non-IP response: ' . $invalidIp);
}
GrantPolicy::verify(['GRANT USAGE ON *.* TO `r`@`localhost`', 'GRANT SELECT ON `whmcs_test`.* TO `r`@`localhost`'], 'whmcs_test');
expect(true, 'Direct schema-scoped SELECT grant accepted');
foreach (['INSERT', 'UPDATE', 'DELETE', 'EXECUTE', 'FILE', 'ALL PRIVILEGES', 'CREATE', 'ALTER', 'SUPER'] as $privilege) {
    rejects(fn () => GrantPolicy::verify(["GRANT SELECT, $privilege ON `whmcs_test`.* TO `r`@`localhost`"], 'whmcs_test'), $privilege . ' source grant rejected');
}
rejects(fn () => GrantPolicy::verify(['GRANT SELECT ON *.* TO `r`@`localhost`'], 'whmcs_test'), 'Global SELECT rejected');
rejects(fn () => GrantPolicy::verify(['GRANT `readrole`@`%` TO `r`@`localhost`'], 'whmcs_test'), 'Unverifiable role grant rejected');
rejects(fn () => GrantPolicy::verify(['GRANT SELECT ON `whmcs_test`.* TO `r`@`localhost` WITH GRANT OPTION'], 'whmcs_test'), 'Grant option rejected');
rejects(fn () => GrantPolicy::verify(['GRANT SELECT (`id`) ON `whmcs_test`.`tblclients` TO `r`@`localhost`'], 'whmcs_test'), 'Column-only access rejected');
expect(Reader::identifier('tblclients') === '`tblclients`', 'Known identifier quoted');
rejects(fn () => Reader::identifier('tblclients` UNION SELECT'), 'Query identifier injection rejected');
$vault = new Vault(str_repeat('x', 32));
$sealed = $vault->seal(['password' => 'a-secret']);
expect(!str_contains($sealed, 'a-secret') && $vault->open($sealed)['password'] === 'a-secret', 'Authenticated credential encryption roundtrip');
$bytes = base64_decode($sealed);
$bytes[30] = chr(ord($bytes[30]) ^ 1);
rejects(fn () => $vault->open(base64_encode($bytes)), 'Tampered credential ciphertext rejected');
rejects(fn () => (new Vault(str_repeat('z', 32)))->open($sealed), 'Incorrect encryption key rejected');
expect(Decimal::money('90071992547409.91') === '90071992547409.91', 'Large financial amounts retain exact decimal precision');
expect(Decimal::add('0.10', '0.20') === '0.30000000', 'Financial addition never uses floating point');
rejects(fn () => Decimal::money(0.1), 'Floating-point input refused');
rejects(fn () => Decimal::money('1.001'), 'Precision loss refused');
expect(NativeWriter::date('2004-02-29') === '2004-02-29 00:00:00', 'Historical leap-day registration date preserved');
rejects(fn () => NativeWriter::date('2025-02-29'), 'Invalid calendar date rejected');
rejects(fn () => NativeWriter::date('0000-00-00'), 'Zero date rejected without substitution');
expect(Sensitive::field('password') && Sensitive::field('accesshash') && Sensitive::table('paymentgateways'), 'Sensitive columns/tables detected');
expect(Sensitive::field('configoption1') && Sensitive::field('moduleconfigoption2') && !Sensitive::field('packagename'), 'Opaque module configuration slots cannot leak secrets through generic product archiving');
expect(str_contains(Sanitizer::text('password=do-not-retain'), '[REDACTED]'), 'Credential-like historical free text redacted');
expect(!str_contains(Sanitizer::text('https://example.com/reset?token=do-not-retain'), 'do-not-retain'), 'Token-bearing historical URL redacted');
expect(Exporter::csvCell('=1+1') === "'=1+1", 'CSV formula injection neutralized');
$schema = ['tblclients' => ['suffix' => 'clients', 'key' => 'id', 'classification' => 'archive', 'columns' => [
    'id' => ['type' => 'int', 'sensitive' => false], 'email' => ['type' => 'varchar(255)', 'sensitive' => false],
    'password' => ['type' => 'varchar(255)', 'sensitive' => true],
]]];
$profile = Profile::defaults($schema);
expect($profile['fields']['tblclients']['password']['action'] === 'exclude_sensitive', 'Sensitive mappings excluded by default');
expect(Profile::validate($profile, $schema) === $profile, 'Default mapping validates');
$profile['fields']['tblclients']['id']['action'] = 'skip';
rejects(fn () => Profile::validate($profile, $schema), 'Skipping source identity rejected');
$profile = Profile::defaults($schema);
$profile['fields']['tblclients']['email']['target'] = 'pass';
rejects(fn () => Profile::validate($profile, $schema), 'Mapping customer input into credential fields rejected');
$profile = Profile::defaults($schema);
$profile['fields']['tblclients']['email']['transform'] = 'eval';
rejects(fn () => Profile::validate($profile, $schema), 'Executable transformation rejected');
expect(\Box\Mod\Whmcsmigrator\Mapping\EventDate::utc('2010-01-01 05:30:00', 'Asia/Kolkata') === '2010-01-01 00:00:00', 'Verified source timezone converts event timestamps to UTC');
expect(\Box\Mod\Whmcsmigrator\Mapping\EventDate::utc('2010-01-01', null) === '2010-01-01 00:00:00', 'Business calendar date is not shifted by timezone conversion');
rejects(fn () => \Box\Mod\Whmcsmigrator\Mapping\EventDate::utc('2010-01-01 00:00:00', null), 'Unknown source event timezone blocks native timestamp import');
rejects(fn () => \Box\Mod\Whmcsmigrator\Mapping\EventDate::utc('2026-03-08 02:30:00', 'America/New_York'), 'Nonexistent daylight-saving timestamp rejected');
rejects(fn () => \Box\Mod\Whmcsmigrator\Mapping\EventDate::utc('2026-11-01 01:30:00', 'America/New_York'), 'Ambiguous repeated daylight-saving timestamp rejected');
expect(Decimal::tax('19.99', '10') === '2.00' && Decimal::round('-1.005') === '-1.01', 'Decimal-only native tax compatibility and half-up rounding');
$price = [];
foreach (\Box\Mod\Whmcsmigrator\Destination\ProductPricing::CYCLES as $column => [$target, $setup]) {
    $price[$column] = '12.34';
    $price[$setup] = '1.23';
}
$matrix = \Box\Mod\Whmcsmigrator\Destination\ProductPricing::matrix($price, 'recurrent');
expect($matrix['native']['b_price'] === '12.34' && $matrix['native']['bia_setup_price'] === '1.23', 'Semiannual/biennial price and setup column mapping verified');
$price['monthly'] = '-1.00';
expect(\Box\Mod\Whmcsmigrator\Destination\ProductPricing::matrix($price, 'recurrent')['native']['m_enabled'] === 0, 'Negative-one source sentinel disables a cycle, not a negative charge');
rejects(fn () => \Box\Mod\Whmcsmigrator\Destination\ProductPricing::matrix($price, 'once'), 'One-time price cannot use a disabled monthly slot');
$price['monthly'] = '-0.50';
rejects(fn () => \Box\Mod\Whmcsmigrator\Destination\ProductPricing::matrix($price, 'recurrent'), 'Unsupported negative price semantics rejected');
$price['monthly'] = '12.34';
unset($price['tsetupfee']);
rejects(fn () => \Box\Mod\Whmcsmigrator\Destination\ProductPricing::matrix($price, 'recurrent'), 'Missing source pricing fields are not replaced by zero');
rejects(fn () => \Box\Mod\Whmcsmigrator\Mapping\PreviewValidator::validate('accounts', ['date' => '2010-01-01 00:00:00', 'invoiceid' => 1, 'currency' => 1, 'amountin' => '0.00', 'amountout' => '0.00'], 'UTC'), 'Fees-only/empty source movement cannot become a fabricated payment');
$saved = Profile::defaults($schema);
$saved['fields']['tblclients']['email']['transform'] = 'lowercase';
$extended = $schema;
$extended['tblclients']['columns']['firstname'] = ['type' => 'varchar(100)', 'sensitive' => false];
$rebased = Profile::rebase($saved, $extended);
expect($rebased['fields']['tblclients']['email']['transform'] === 'lowercase' && $rebased['fields']['tblclients']['firstname']['target'] === 'first_name', 'Schema review preserves compatible saved rules and adds defaults only for new fields');
$saved['fields']['tblclients']['email']['target'] = 'first_name';
rejects(fn () => Profile::rebase($saved, $extended), 'Conflicting saved/schema mappings require explicit review instead of silent reset');
echo "Unit suite finished.\n";
