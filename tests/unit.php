<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Box\Mod\Whmcstofossbilling\Archive\Sanitizer;
use Box\Mod\Whmcstofossbilling\Destination\NativeWriter;
use Box\Mod\Whmcstofossbilling\Finance\Decimal;
use Box\Mod\Whmcstofossbilling\Mapping\Profile;
use Box\Mod\Whmcstofossbilling\Report\Exporter;
use Box\Mod\Whmcstofossbilling\Security\Sensitive;
use Box\Mod\Whmcstofossbilling\Security\Vault;
use Box\Mod\Whmcstofossbilling\Source\ConnectionOptions;
use Box\Mod\Whmcstofossbilling\Source\GrantPolicy;
use Box\Mod\Whmcstofossbilling\Source\Reader;

$options = ['host' => '127.0.0.1', 'database' => 'whmcs_test', 'username' => 'reader', 'password' => 'test-only', 'security_acknowledged' => true];
expect(ConnectionOptions::validate($options)['port'] === 3306, 'Port defaults to 3306');
expect(ConnectionOptions::validate($options + ['prefix' => 'custom_'])['prefix'] === 'custom_', 'Custom identifier prefix accepted');
rejects(fn () => ConnectionOptions::validate(array_replace($options, ['host' => 'https://example.com'])), 'HTTP URLs rejected');
rejects(fn () => ConnectionOptions::validate(array_replace($options, ['host' => 'localhost;dbname=other'])), 'DSN injection rejected');
rejects(fn () => ConnectionOptions::validate($options + ['prefix' => 'tbl;DROP'] ), 'Identifier injection rejected');
rejects(fn () => ConnectionOptions::validate($options + ['port' => 0]), 'Invalid port rejected');
rejects(fn () => ConnectionOptions::validate($options + ['timeout' => 60]), 'Unbounded connection timeout rejected');
expect(ConnectionOptions::validate(array_replace($options, ['host' => 'db.example.com']))['security_mode'] === 'standard', 'Standard MySQL connection is the default when no security field is supplied');
rejects(fn () => ConnectionOptions::validate(array_replace($options, ['security_mode' => 'standard', 'security_acknowledged' => false])), 'Standard mode requires explicit administrator risk acknowledgement');
rejects(fn () => ConnectionOptions::validate(array_replace($options, ['tls' => false, 'security_acknowledged' => false])), 'Legacy tls=false form/API input cannot bypass explicit acknowledgement, even on loopback');
expect(ConnectionOptions::validate($options + ['tls' => false], true)['security_mode'] === 'standard', 'Only trusted already-stored 0.1 local profiles retain legacy non-TLS compatibility');
rejects(fn () => ConnectionOptions::validate(array_replace($options, ['security_mode' => 'unverified_tls', 'security_acknowledged' => false])), 'Unverified TLS requires explicit administrator risk acknowledgement');
rejects(fn () => ConnectionOptions::validate(array_replace($options, ['security_mode' => 'standard', 'security_acknowledged' => 'false'])), 'String false cannot authorize a weaker security mode');
$plain = ConnectionOptions::validate(array_replace($options, ['host' => 'db.example.com', 'security_mode' => 'standard', 'security_acknowledged' => true, 'tls_ca' => 'D:/not-required.pem']));
expect($plain['tls'] === false && $plain['tls_ca'] === '', 'Explicit remote standard mode works without a readable CA path');
expect(!isset(\Box\Mod\Whmcstofossbilling\Source\ConnectionFactory::attributes($plain)[PDO::MYSQL_ATTR_SSL_CA]), 'Standard PDO attributes do not request TLS or require a TLS hostname/CA');
$unverified = ConnectionOptions::validate($options + ['security_mode' => 'unverified_tls', 'security_acknowledged' => true]);
$attrs = \Box\Mod\Whmcstofossbilling\Source\ConnectionFactory::attributes($unverified);
expect(isset($attrs[PDO::MYSQL_ATTR_SSL_CA]) && $attrs[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] === false, 'Unverified mode explicitly requests encryption while disabling only identity verification');
rejects(fn () => ConnectionOptions::assertPlan($plain, ['source_security_mode' => 'verified_tls']), 'Existing run security cannot silently downgrade after profile retesting');
rejects(fn () => ConnectionOptions::validate($options + ['tls' => true, 'tls_ca' => 'https://example.com/ca']), 'Remote certificate URL rejected');
$tlsOptions = ConnectionOptions::validate(array_replace($options, ['host' => 'db.example.com', 'tls' => true]));
expect(\Box\Mod\Whmcstofossbilling\Source\CaBundle::readable($tlsOptions['tls_ca']), 'Remote TLS automatically uses a readable default CA bundle when no custom path is supplied');
$bundled = dirname(__DIR__) . '/certificates/cacert.pem';
$certificates = file_get_contents($bundled);
preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificates, $matches);
expect(count($matches[0]) > 100 && array_reduce($matches[0], static fn (bool $ok, string $pem): bool => $ok && openssl_x509_read($pem) !== false, true), 'Bundled trust store contains parseable real CA certificates, not a generated placeholder');
rejects(fn () => ConnectionOptions::validate($options + ['tls' => true, 'tls_ca' => 'D:/missing-whmcstofossbilling-ca.pem']), 'Invalid explicit CA override is rejected instead of silently ignored');
rejects(fn () => ConnectionOptions::validate($options + ['tls' => true, 'tls_ca' => '\\\\server\\share\\ca.pem']), 'Network-share certificate path rejected');
expect(ConnectionOptions::validate($options + ['source_timezone' => 'UTC', 'source_timezone_verified' => false])['source_timezone'] === '', 'Suggested timezone cannot become verified source evidence without confirmation');
expect(ConnectionOptions::validate($options + ['source_timezone' => 'Asia/Kolkata', 'source_timezone_verified' => true])['source_timezone'] === 'Asia/Kolkata', 'Explicitly verified dropdown timezone is preserved');
expect(\Box\Mod\Whmcstofossbilling\Source\PublicIp::parse("8.8.8.8\n") === '8.8.8.8', 'IP response parser accepts a public IPv4 address');
foreach (['127.0.0.1', '192.168.1.1', '::1', '<script>8.8.8.8</script>', 'not-an-ip'] as $invalidIp) {
    rejects(fn () => \Box\Mod\Whmcstofossbilling\Source\PublicIp::parse($invalidIp), 'IP response parser rejects private/non-IPv4/non-IP response: ' . $invalidIp);
}
$zone = \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::unselected();
expect($zone['timezone'] === null && $zone['status'] === 'unverified' && $zone['method'] === 'administrator_selection_required', 'No automatic timezone inference remains; an unselected source stays unverified');
$selectedZone = \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::selected('Asia/Kolkata');
expect($selectedZone['timezone'] === 'Asia/Kolkata' && $selectedZone['confidence'] === 'administrator_verified' && $selectedZone['method'] === 'administrator_selected_application_timezone', 'Administrator-selected application timezone is recorded as verified profile evidence');
expect(\Box\Mod\Whmcstofossbilling\Source\SourceTimezone::selected('America/Chicago')['timezone'] === 'America/Chicago', 'Default dropdown timezone is a valid selectable IANA identifier');
rejects(fn () => \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::selected('-06:00'), 'A UTC offset cannot be promoted to a historical IANA timezone');
$timestampSchema = ['tblclients' => ['suffix' => 'clients', 'columns' => ['datecreated' => ['type' => 'timestamp']]]];
rejects(fn () => \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::assertNativeStorage($timestampSchema, ['tblclients'], 'reference_native'), 'Session-converted MySQL TIMESTAMP storage cannot masquerade as application-local DATETIME');
\Box\Mod\Whmcstofossbilling\Source\SourceTimezone::assertNativeStorage($timestampSchema, ['tblclients'], 'archive');
expect(true, 'Unreviewed TIMESTAMP storage remains available for unchanged private archive preservation');
foreach ([
    ['SQLSTATE[HY000] [2002] certificate verify failed password=NEVER_EXPOSE', 'TLS certificate validation failed'],
    ['SQLSTATE[HY000] [2026] SSL handshake failure', 'TLS negotiation failed'],
    ['SQLSTATE[HY000] [2002] connection timed out', 'connection timed out'],
    ['SQLSTATE[HY000] [2002] connection refused', 'port refused'],
    ['SQLSTATE[HY000] [2002] php_network_getaddresses getaddrinfo failed', 'hostname could not be resolved'],
    ['SQLSTATE[HY000] [2002] no route to host', 'host is unreachable'],
] as [$driverText, $expected]) {
    $message = \Box\Mod\Whmcstofossbilling\Source\ConnectionDiagnostics::message(new RuntimeException($driverText));
    expect(str_contains($message, $expected) && !str_contains($message, 'NEVER_EXPOSE'), 'Connection diagnostics classify ' . $expected . ' without leaking raw driver text');
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
expect(\Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('2010-01-01 05:30:00', 'Asia/Kolkata') === '2010-01-01 00:00:00', 'Verified source timezone converts event timestamps to UTC');
expect(\Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('2010-01-01', null) === '2010-01-01 00:00:00', 'Business calendar date is not shifted by timezone conversion');
rejects(fn () => \Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('2010-01-01 00:00:00', null), 'Unknown source event timezone blocks native timestamp import');
rejects(fn () => \Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('2026-03-08 02:30:00', 'America/New_York'), 'Nonexistent daylight-saving timestamp rejected');
rejects(fn () => \Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('2026-11-01 01:30:00', 'America/New_York'), 'Ambiguous repeated daylight-saving timestamp rejected');
$usedFallback = true;
expect(\Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('2010-01-01', null, NativeWriter::DATE_FALLBACK, $usedFallback) === '2010-01-01 00:00:00' && $usedFallback === false, 'Valid dates never report a substitution');
$usedFallback = false;
expect(\Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('0000-00-00', null, NativeWriter::DATE_FALLBACK, $usedFallback) === NativeWriter::DATE_FALLBACK && $usedFallback, 'Explicit opt-in substitutes a safe sentinel for an invalid calendar date');
$usedFallback = false;
expect(\Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('0000-00-00 00:00:00', 'UTC', NativeWriter::DATE_FALLBACK, $usedFallback) === NativeWriter::DATE_FALLBACK && $usedFallback, 'Explicit opt-in also covers impossible zero timestamps');
$usedFallback = false;
rejects(fn () => \Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('2026-03-08 02:30:00', 'America/New_York', NativeWriter::DATE_FALLBACK, $usedFallback), 'Daylight-saving gaps are never replaced by a sentinel');
$usedFallback = false;
rejects(fn () => \Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('not a date', null, NativeWriter::DATE_FALLBACK, $usedFallback), 'Malformed non-date text still requires review');
rejects(fn () => \Box\Mod\Whmcstofossbilling\Mapping\EventDate::utc('0000-00-00', null), 'Strict default still rejects zero dates without the explicit policy');
expect(Decimal::tax('19.99', '10') === '2.00' && Decimal::round('-1.005') === '-1.01', 'Decimal-only native tax compatibility and half-up rounding');
$price = [];
foreach (\Box\Mod\Whmcstofossbilling\Destination\ProductPricing::CYCLES as $column => [$target, $setup]) {
    $price[$column] = '12.34';
    $price[$setup] = '1.23';
}
$matrix = \Box\Mod\Whmcstofossbilling\Destination\ProductPricing::matrix($price, 'recurrent');
expect($matrix['native']['b_price'] === '12.34' && $matrix['native']['bia_setup_price'] === '1.23', 'Semiannual/biennial price and setup column mapping verified');
$price['monthly'] = '-1.00';
expect(\Box\Mod\Whmcstofossbilling\Destination\ProductPricing::matrix($price, 'recurrent')['native']['m_enabled'] === 0, 'Negative-one source sentinel disables a cycle, not a negative charge');
rejects(fn () => \Box\Mod\Whmcstofossbilling\Destination\ProductPricing::matrix($price, 'once'), 'One-time price cannot use a disabled monthly slot');
$price['monthly'] = '-0.50';
rejects(fn () => \Box\Mod\Whmcstofossbilling\Destination\ProductPricing::matrix($price, 'recurrent'), 'Unsupported negative price semantics rejected');
$price['monthly'] = '12.34';
unset($price['tsetupfee']);
rejects(fn () => \Box\Mod\Whmcstofossbilling\Destination\ProductPricing::matrix($price, 'recurrent'), 'Missing source pricing fields are not replaced by zero');
rejects(fn () => \Box\Mod\Whmcstofossbilling\Mapping\PreviewValidator::validate('accounts', ['date' => '2010-01-01 00:00:00', 'invoiceid' => 1, 'currency' => 1, 'amountin' => '0.00', 'amountout' => '0.00'], 'UTC'), 'Fees-only/empty source movement cannot become a fabricated payment');
$saved = Profile::defaults($schema);
$saved['fields']['tblclients']['email']['transform'] = 'lowercase';
$extended = $schema;
$extended['tblclients']['columns']['firstname'] = ['type' => 'varchar(100)', 'sensitive' => false];
$rebased = Profile::rebase($saved, $extended);
expect($rebased['fields']['tblclients']['email']['transform'] === 'lowercase' && $rebased['fields']['tblclients']['firstname']['target'] === 'first_name', 'Schema review preserves compatible saved rules and adds defaults only for new fields');
$saved['fields']['tblclients']['email']['target'] = 'first_name';
rejects(fn () => Profile::rebase($saved, $extended), 'Conflicting saved/schema mappings require explicit review instead of silent reset');
$sizer = \Box\Mod\Whmcstofossbilling\Migration\BatchSizer::class;
expect($sizer::clamp(0) === 1 && $sizer::clamp(99999) === 2000 && $sizer::MAX_ROWS === 2000 && $sizer::START_ROWS === 500, 'Adaptive batches stay inside the hard 1–2000 row bounds');
expect($sizer::initial(9000, null) === 2000 && $sizer::initial(-4, null) === 500, 'Requested sizes are clamped and a missing value starts conservatively');
expect($sizer::initial(500, ['next_limit' => 750, 'batch_limit' => 900]) === 750, 'A later drive call inherits the last measured next-limit decision');
expect($sizer::next(500, ['batch_processed' => 500, 'elapsed_ms' => 900, 'page_bytes' => 1048576, 'page_exhausted' => true]) === 750, 'Fast, small pages grow the next batch');
expect($sizer::next(1000, ['batch_processed' => 1000, 'elapsed_ms' => 4700, 'page_bytes' => 1048576, 'page_exhausted' => true]) === 750, 'Batches approaching the five-second work cap shrink immediately');
expect($sizer::next(1000, ['batch_processed' => 1000, 'elapsed_ms' => 900, 'page_bytes' => 6291456, 'page_exhausted' => true]) === 750, 'Pages approaching the 8 MiB budget shrink instead of risking a larger read');
expect($sizer::next(800, ['batch_processed' => 0, 'elapsed_ms' => 10, 'page_bytes' => 0, 'page_exhausted' => false]) === 400, 'Empty batches back off rather than spinning at the same size');
expect($sizer::next(500, ['batch_processed' => 500, 'elapsed_ms' => 3000, 'page_bytes' => 5242880, 'page_exhausted' => true]) === 500, 'Mixed measurements keep the current conservative size');
echo "Unit suite finished.\n";
