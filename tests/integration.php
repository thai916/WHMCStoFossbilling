<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Box\Mod\Whmcstofossbilling\Destination\Store;
use Box\Mod\Whmcstofossbilling\Installation\Schema;
use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Service;
use Box\Mod\Whmcstofossbilling\Source\Reader;

if (!in_array('--disposable-databases', $argv, true)) {
    exit("Explicit --disposable-databases flag required. Creates and removes uniquely named local test databases/accounts; never accepts live source credentials.\n");
}
$config = require PATH_CONFIG;
$db = $config['db'];
if (!in_array($db['host'], ['localhost', '127.0.0.1', '::1'], true)) {
    exit("Refused: the FOSSBilling destination is not local.\n");
}
$admin = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';charset=utf8mb4', $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$serverIdentity = $admin->query('SELECT @@server_uuid')->fetchColumn();
$fixtureHost = null;
foreach (['127.0.0.1', '::1'] as $candidateHost) {
    try {
        $candidate = new PDO('mysql:host=' . $candidateHost . ';port=' . ($db['port'] ?? 3306) . ';charset=utf8mb4', $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3]);
        if ($candidate->query('SELECT @@server_uuid')->fetchColumn() === $serverIdentity) {
            $fixtureHost = $candidateHost;
            $admin = $candidate;
            break;
        }
    } catch (PDOException) {
        // Never output DBA credentials or alter a server reached at the wrong endpoint.
    }
}
if ($fixtureHost === null) {
    fwrite(STDERR, "No verified TCP loopback endpoint reaches the configured local MySQL instance. No fixtures were created.\n");
    exit(1);
}
$token = bin2hex(random_bytes(5));
$sourceDb = 'wm_test_source_' . $token;
$destinationDb = 'wm_test_destination_' . $token;
$user = 'wm_test_' . $token;
$password = bin2hex(random_bytes(24));
$service = null;
$dumpJobId = null;
$sqlDestinationDb = null;

try {
    foreach ([$sourceDb, $destinationDb] as $name) {
        $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    }
    // Clone destination STRUCTURE only. Never insert/update/delete actual FOSSBilling records.
    $required = ['client', 'client_group', 'currency', 'product', 'product_category', 'product_payment', 'client_order', 'service_hosting', 'service_hosting_server', 'service_hosting_hp', 'service_domain', 'invoice', 'invoice_item', 'transaction', 'client_balance', 'support_helpdesk', 'support_ticket', 'support_ticket_message', 'support_ticket_note', 'activity_client_email', 'email_queue'];
    $admin->exec("USE `$destinationDb`");
    foreach ($required as $table) {
        $create = $admin->query('SHOW CREATE TABLE `' . $db['name'] . '`.`' . $table . '`')->fetch();
        $admin->exec($create['Create Table']);
    }
    $admin->exec("INSERT INTO currency (code,is_default,conversion_rate) VALUES ('USD',1,1.000000)");
    $admin->exec("USE `$sourceDb`");
    $sourceTables = [
        'tblcurrencies' => 'id INT PRIMARY KEY, code VARCHAR(3), rate DECIMAL(13,6)',
        'tblclientgroups' => 'id INT PRIMARY KEY, groupname VARCHAR(100)',
        'tblclients' => 'id INT PRIMARY KEY, firstname VARCHAR(100), lastname VARCHAR(100), email VARCHAR(255), password VARCHAR(255), currency INT, groupid INT, datecreated DATE, status VARCHAR(20), notes TEXT, credit DECIMAL(18,2)',
        'tblcontacts' => 'id INT PRIMARY KEY, userid INT, firstname VARCHAR(100), email VARCHAR(255)',
        'tblproductgroups' => 'id INT PRIMARY KEY, name VARCHAR(100), headline TEXT, `order` INT',
        'tblproducts' => 'id INT PRIMARY KEY, gid INT, name VARCHAR(100), description TEXT, type VARCHAR(30), paytype VARCHAR(30), stockcontrol INT, qty INT, `order` INT, servertype VARCHAR(30), servergroup INT, packagename VARCHAR(100)',
        'tblservers' => 'id INT PRIMARY KEY, name VARCHAR(100), hostname VARCHAR(100), ipaddress VARCHAR(45), type VARCHAR(30), port INT, secure VARCHAR(10), username VARCHAR(100), password TEXT, accesshash TEXT, nameserver1 VARCHAR(100), nameserver2 VARCHAR(100)',
        'tblservergroups' => 'id INT PRIMARY KEY, name VARCHAR(100)',
        'tblservergroupsrel' => 'id INT PRIMARY KEY, groupid INT, serverid INT',
        'tbladdons' => 'id INT PRIMARY KEY, name VARCHAR(100), description TEXT, billingcycle VARCHAR(30), packages TEXT',
        'tblpricing' => 'id INT PRIMARY KEY, type VARCHAR(30), relid INT, currency INT, monthly DECIMAL(18,2), quarterly DECIMAL(18,2), semiannually DECIMAL(18,2), annually DECIMAL(18,2), biennially DECIMAL(18,2), triennially DECIMAL(18,2), msetupfee DECIMAL(18,2), qsetupfee DECIMAL(18,2), ssetupfee DECIMAL(18,2), asetupfee DECIMAL(18,2), bsetupfee DECIMAL(18,2), tsetupfee DECIMAL(18,2)',
        'tblhosting' => 'id INT PRIMARY KEY, userid INT, packageid INT, server INT, domain VARCHAR(100), username VARCHAR(100), password VARCHAR(255), regdate DATE, nextduedate DATE, amount DECIMAL(18,2), domainstatus VARCHAR(30), billingcycle VARCHAR(30), dedicatedip VARCHAR(45), firstpaymentamount DECIMAL(18,2), termination_date DATE',
        'tbldomains' => 'id INT PRIMARY KEY, userid INT, domain VARCHAR(100), expirydate DATE, registrationdate DATE, status VARCHAR(30), eppcode VARCHAR(255)',
        'tblinvoices' => 'id INT PRIMARY KEY, userid INT, invoicenum VARCHAR(100), date DATE, duedate DATE, datepaid DATETIME, subtotal DECIMAL(18,2), tax DECIMAL(18,2), tax2 DECIMAL(18,2), credit DECIMAL(18,2), total DECIMAL(18,2), status VARCHAR(30), currency INT, taxrate DECIMAL(10,6)',
        'tblinvoiceitems' => 'id INT PRIMARY KEY, invoiceid INT, userid INT, type VARCHAR(30), relid INT, description TEXT, amount DECIMAL(18,2), taxed INT',
        'tblaccounts' => 'id INT PRIMARY KEY, userid INT, invoiceid INT, date DATETIME, gateway VARCHAR(50), transid VARCHAR(255), amountin DECIMAL(18,2), amountout DECIMAL(18,2), fees DECIMAL(18,2), currency INT',
        'tblcredit' => 'id INT PRIMARY KEY, clientid INT, date DATETIME, description TEXT, amount DECIMAL(18,2)',
        'tbltickets' => 'id INT PRIMARY KEY, tid VARCHAR(100), userid INT, title VARCHAR(255), date DATETIME, status VARCHAR(30), message TEXT',
        'tblticketreplies' => 'id INT PRIMARY KEY, tid INT, userid INT, date DATETIME, message TEXT, admin VARCHAR(100)',
        'tblticketnotes' => 'id INT PRIMARY KEY, ticketid INT, date DATETIME, message TEXT, admin VARCHAR(100)',
        'tblticketdepartments' => 'id INT PRIMARY KEY, name VARCHAR(100), email VARCHAR(100)',
        'tblemails' => 'id INT PRIMARY KEY, userid INT, date DATETIME, subject VARCHAR(255), message TEXT',
        'tblnotes' => 'id INT PRIMARY KEY, userid INT, created DATETIME, note TEXT',
        'tblconfiguration' => 'id INT PRIMARY KEY, setting VARCHAR(100), value TEXT',
        'tblcustomfieldsvalues' => 'id INT PRIMARY KEY, fieldid INT, relid INT, value TEXT',
        'tblunknownmodule' => 'id INT PRIMARY KEY, config TEXT',
    ];
    foreach ($sourceTables as $name => $columns) {
        $admin->exec("CREATE TABLE `$name` ($columns) ENGINE=InnoDB");
    }
    $admin->exec("INSERT INTO tblcurrencies VALUES (1,'USD',1.000000),(2,'EUR',0.900000)");
    $admin->exec("INSERT INTO tblclientgroups VALUES (1,'Legacy')");
    $admin->exec("INSERT INTO tblclients VALUES (1,'Ada','Example','ada@example.invalid','DO_NOT_EXTRACT',1,1,'2004-02-29','Active','password=DO_NOT_ARCHIVE',25.00),(2,'Lin','Example','lin@example.invalid','DO_NOT_EXTRACT',2,1,'2012-01-01','Closed','Historical note',0.00),(3,'Mismatch','Fixture','mismatch@example.invalid','DO_NOT_EXTRACT',1,1,'2013-01-01','Active','Deliberately inconsistent credit ledger',5.00)");
    $admin->exec("INSERT INTO tblcontacts VALUES (1,1,'Contact','contact@example.invalid')");
    $admin->exec("INSERT INTO tblproductgroups VALUES (1,'Hosting','Legacy hosting',1)");
    $admin->exec("INSERT INTO tblproducts VALUES (1,1,'Plan A','Original plan','hostingaccount','recurring',1,10,1,'cpanel',1,'legacy_plan'),(2,1,'Free Plan','Free','other','free',0,0,2,'',0,''),(3,1,'Once Plan','Once','other','onetime',0,0,3,'',0,'')");
    $admin->exec("INSERT INTO tblservers VALUES (1,'Production reference','panel.example.invalid','192.0.2.10','cpanel',2087,'on','DO_NOT_EXTRACT','DO_NOT_EXTRACT','DO_NOT_EXTRACT','ns1.example.invalid','ns2.example.invalid')");
    $admin->exec("INSERT INTO tblservergroups VALUES (1,'Legacy group')");
    $admin->exec("INSERT INTO tblservergroupsrel VALUES (1,1,1)");
    $admin->exec("INSERT INTO tbladdons VALUES (1,'Backup add-on','Existing add-on','One Time','1')");
    $admin->exec("INSERT INTO tblpricing VALUES (1,'product',1,1,10.00,27.00,-1.00,100.00,180.00,250.00,1.00,2.00,0.00,4.00,5.00,6.00),(2,'product',1,2,9.00,24.00,-1.00,90.00,160.00,220.00,1.00,2.00,0.00,4.00,5.00,6.00),(3,'addon',1,1,5.00,-1.00,-1.00,-1.00,-1.00,-1.00,2.00,0.00,0.00,0.00,0.00,0.00),(4,'product',2,1,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00),(5,'product',3,1,50.00,-1.00,-1.00,-1.00,-1.00,-1.00,3.00,0.00,0.00,0.00,0.00,0.00)");
    $admin->exec("INSERT INTO tblhosting VALUES (1,1,1,1,'example.invalid','existinguser','DO_NOT_EXTRACT','2010-01-01','2020-01-01',10.00,'Suspended','Monthly',NULL,10.00,NULL),(2,1,1,1,'terminated.example.invalid','olduser','DO_NOT_EXTRACT','2008-01-01','2009-01-01',7.50,'Terminated','Monthly','198.51.100.50',12.00,'2009-02-01')");
    $admin->exec("INSERT INTO tbldomains VALUES (1,1,'example.invalid','2030-01-01','2010-01-01','Active','DO_NOT_EXTRACT')");
    $admin->exec("INSERT INTO tblinvoices VALUES (1,1,'LEGACY-001','2010-01-01','2010-01-15','2010-01-05 12:00:00',100.00,10.00,5.00,25.00,115.00,'Paid',1,10),(2,1,'LEGACY-002','2010-02-01','2010-02-15',NULL,50.00,0.00,0.00,0.00,50.00,'Unpaid',1,0),(3,2,'003','2011-01-01','2011-01-15','2011-01-05 12:00:00',20.00,2.00,0.00,0.00,22.00,'Paid',2,10)");
    $admin->exec("INSERT INTO tblinvoiceitems VALUES (1,1,1,'Hosting',1,'Original line',100.00,1),(2,2,1,'Hosting',1,'Partial payment invoice',50.00,0),(3,3,2,'Hosting',1,'Exact single-tax line',20.00,1)");
    $admin->exec("INSERT INTO tblaccounts VALUES (1,1,1,'2010-01-05 12:00:00','paypal','REAL-HISTORICAL-REF',90.00,0.00,2.50,1),(2,1,2,'2010-02-05 12:00:00','bank','PARTIAL',10.00,0.00,0.00,1),(3,1,1,'2010-03-05 12:00:00','paypal','REFUND',0.00,5.00,0.00,1),(4,2,3,'2011-01-05 12:00:00','bank','EUR-EXACT',10.00,0.00,1.00,2),(5,2,3,'2011-01-05 12:00:00','bank','EUR-PARTIAL-2',12.00,0.00,0.20,2),(6,2,3,'2011-02-05 12:00:00','bank','EUR-REFUND',0.00,2.00,0.00,2)");
    $admin->exec("INSERT INTO tblcredit VALUES (1,1,'2009-12-01 00:00:00','Credit deposit',50.00),(2,1,'2010-01-05 12:00:00','Applied credit',-25.00),(3,3,'2013-01-01 00:00:00','Incomplete source history',4.00)");
    $admin->exec("INSERT INTO tbltickets VALUES (1,'TICKET-001',1,'Old ticket','2010-01-01 00:00:00','Closed','Original customer message')");
    $admin->exec("INSERT INTO tblticketreplies VALUES (1,1,0,'2010-01-02 00:00:00','Staff reply','Historical operator')");
    $admin->exec("INSERT INTO tblticketnotes VALUES (1,1,'2010-01-03 00:00:00','Private internal note','Historical operator')");
    $admin->exec("INSERT INTO tblticketdepartments VALUES (1,'Legacy helpdesk','helpdesk@example.invalid')");
    $admin->exec("INSERT INTO tblemails VALUES (1,1,'2010-01-04 00:00:00','Old email','<script>alert(1)</script> password=DO_NOT_ARCHIVE')");
    $admin->exec("INSERT INTO tblnotes VALUES (1,1,'2010-01-04 00:00:00','Old admin note')");
    $admin->exec("INSERT INTO tblconfiguration VALUES (1,'Version','8.10.1'),(2,'Secret','DO_NOT_EXTRACT')");
    $admin->exec("CREATE USER '$user'@'localhost' IDENTIFIED BY '$password'");
    $admin->exec("GRANT SELECT ON `$sourceDb`.* TO '$user'@'localhost'");
    $sourceDigest = static function () use ($admin, $sourceDb, $sourceTables): string {
        $data = [];
        foreach ($sourceTables as $name => $_) {
            $data[$name] = $admin->query("SELECT * FROM `$sourceDb`.`$name` ORDER BY id")->fetchAll();
        }

        return hash('sha256', Store::json($data));
    };
    $before = $sourceDigest();
    $destination = new PDO('mysql:host=' . $fixtureHost . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $destinationDb . ';charset=utf8mb4', $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $di = new Pimple\Container();
    $di['dbal'] = new class($destination) {
        public function __construct(private PDO $pdo) {}
        public function getNativeConnection(): PDO { return $this->pdo; }
    };
    $service = new Service();
    $service->setDi($di);
    $destination->exec('CREATE TABLE `whmcsmigrator_source` (legacy_probe INT NULL)');
    expect($service->install(), 'Legacy extension tables are renamed into the new module namespace without touching core schema');
    expect((int) $destination->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='whmcsmigrator_source'")->fetchColumn() === 0
        && (int) $destination->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='whmcstofossbilling_source'")->fetchColumn() === 1
        && $destination->query("SHOW COLUMNS FROM `whmcstofossbilling_source` LIKE 'legacy_probe'")->fetch() !== false, 'Extension-owned legacy data survives the module rename');
    $destination->exec('DROP TABLE `whmcstofossbilling_source`');
    expect($service->install() && $service->install(), 'Install and repeat install succeed without core changes');
    if (!defined('PATH_MODS')) define('PATH_MODS', dirname(__DIR__, 2));
    $di['filesystem'] = new \Symfony\Component\Filesystem\Filesystem();
    $nativeModule = new \FOSSBilling\Module('whmcstofossbilling');
    $nativeModule->setDi($di);
    expect($nativeModule->install() && $service->store()->schemaReady(), 'Native FOSSBilling module installer creates the extension schema in the disposable destination');
    $options = ['host' => $fixtureHost, 'port' => $db['port'] ?? 3306, 'database' => $sourceDb, 'username' => $user, 'password' => $password, 'prefix' => 'tbl', 'timeout' => 3, 'security_mode' => 'standard', 'security_acknowledged' => true, 'source_timezone' => 'UTC'];
    $profile = $service->testConnection($options);
    expect($profile['version'] === '8.10.1', 'Source WHMCS version detected without reading other settings');
    expect($profile['schema']['tblunknownmodule']['classification'] === 'requires_review', 'Unknown module tables require manual security review');
    expect($profile['schema']['tblcustomfieldsvalues']['classification'] === 'requires_review', 'Opaque custom-field values require review');
    expect($profile['schema']['tblconfiguration']['classification'] === 'excluded_sensitive', 'Operational configuration excluded');
    expect($profile['connection_status']['successful'] && $profile['connection_status']['security_mode'] === 'standard' && !$profile['connection_status']['tls_active'] && !$profile['connection_status']['server_identity_verified'], 'Successful test reports real security/encryption/identity state without claiming TLS for standard mode');
    expect($profile['connection_status']['database'] === $sourceDb && $profile['connection_status']['table_availability']['available'] && !str_contains(json_encode($profile), $password), 'Connection response includes database/table availability but never the password');
    $unknownZone = $service->testConnection(array_replace($options, ['source_timezone' => '', 'source_timezone_verified' => true]));
    expect($unknownZone['source_timezone'] === '' && $unknownZone['timezone_detection']['status'] === 'unverified' && $unknownZone['timezone_detection']['method'] === 'administrator_selection_required', 'No submitted timezone leaves the source unverified without inspecting MySQL offsets');
    $profile = $service->confirmSourceTimezone($profile['source_id'], 'UTC');
    expect($profile['source_timezone'] === 'UTC' && $profile['timezone_detection']['confidence'] === 'administrator_verified', 'Explicit application timezone selection updates only the encrypted extension profile');
    rejects(fn () => $service->confirmSourceTimezone($profile['source_id'], '-06:00'), 'Database offset cannot authorize timezone-sensitive imports as a fake IANA region');
    expect($sourceDigest() === $before, 'Connection testing and timezone confirmation never modify synthetic source records');
    foreach ([
        ['password' => 'incorrect-fixture-password'],
        ['database' => 'wm_test_missing_' . $token],
        ['port' => 1],
    ] as $invalid) {
        try {
            $service->testConnection(array_replace($options, $invalid));
            expect(false, 'Invalid fixture connection must fail');
        } catch (PDOException|SafeException $error) {
            expect(!str_contains(\Box\Mod\Whmcstofossbilling\Security\Sensitive::error($error), 'incorrect-fixture-password'), 'Connection failure is safely classified without credentials');
        }
    }
    rejects(fn () => $service->testConnection(array_replace($options, ['username' => $db['user'], 'password' => $db['password']])), 'Overprivileged local account refused as source reader');
    $reader = new Reader($options);
    $rows = $reader->batch('tblclients', $profile['schema']['tblclients'], '0', '2', 50);
    expect(!array_key_exists('password', $rows[0]), 'Source password column is not selected');
    unset($reader);
    $selection = [];
    foreach ($profile['schema'] as $name => $definition) {
        if ($definition['classification'] === 'archive') {
            $service->analyze($profile['source_id'], $name);
            $selection[] = $name;
        }
    }
    $runInput = ['source_id' => $profile['source_id'], 'tables' => $selection];
    $analysisStore = $service->store();
    $analysisStore->execute('DELETE FROM whmcstofossbilling_analysis WHERE source_id=? AND table_name=?', [$profile['source_id'], 'tblcurrencies']);
    $analysisStore->execute('UPDATE whmcstofossbilling_analysis SET analyzed_at=UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE source_id=? AND table_name=?', [$profile['source_id'], 'tblclients']);
    $prepared = $service->prepareAnalysis(['source_id' => $profile['source_id'], 'tables' => ['tblclients'], 'target_mode' => 'reference_native']);
    expect(in_array('tblcurrencies', $prepared['tables'], true) && ($prepared['issues']['tblcurrencies'] ?? '') === 'missing' && ($prepared['issues']['tblclients'] ?? '') === 'stale', 'Analysis preparation expands native dependencies and names missing/stale counts');
    try {
        $service->createRun(['source_id' => $profile['source_id'], 'tables' => ['tblclients'], 'mode' => 'dry_run'], 1);
        expect(false, 'Direct API callers cannot bypass count validation');
    } catch (SafeException $error) {
        expect(str_contains($error->getMessage(), 'tblclients (stale)') && str_contains($error->getMessage(), 'tblcurrencies (missing)'), 'Hard planner gate names every missing/stale table');
    }
    foreach (array_keys($prepared['issues']) as $name) $service->analyze($profile['source_id'], $name);
    expect($service->prepareAnalysis(['source_id' => $profile['source_id'], 'tables' => ['tblclients'], 'target_mode' => 'reference_native'])['issues'] === [], 'Read-only refresh resolves the expanded selection');
    $analysisStore->execute('UPDATE whmcstofossbilling_analysis SET schema_checksum=? WHERE source_id=? AND table_name=?', [str_repeat('0', 64), $profile['source_id'], 'tblclients']);
    expect($service->prepareAnalysis(['source_id' => $profile['source_id'], 'tables' => ['tblclients'], 'target_mode' => 'archive'])['issues'] === ['tblclients' => 'schema changed'], 'Schema-mismatched analysis identifies the affected table');
    $service->analyze($profile['source_id'], 'tblclients');
    $admin->exec("ALTER TABLE `$sourceDb`.`tblclients` ADD COLUMN wm_schema_change_fixture INT NULL");
    try {
        $service->analyze($profile['source_id'], 'tblclients');
        expect(false, 'A changed live schema must block count refresh');
    } catch (SafeException $error) {
        expect(str_contains($error->getMessage(), 'tblclients') && str_contains($error->getMessage(), 'Test the connection again'), 'Schema-change failure names the table and recovery action');
    } finally {
        $admin->exec("ALTER TABLE `$sourceDb`.`tblclients` DROP COLUMN wm_schema_change_fixture");
    }
    expect($sourceDigest() === $before, 'Preparation and count refresh leave synthetic WHMCS records unchanged');
    $service->testConnection(array_replace($options, ['source_timezone' => '']));
    try {
        $service->createRun($runInput + ['mode' => 'reference_native', 'confirmation' => 'PRESERVE HISTORY', 'backup_verified' => true, 'backup_reference' => 'disposable timezone fixture', 'accept_limitations' => true], 1);
        expect(false, 'Unverified source timezone must block native event authorization');
    } catch (SafeException $error) {
        expect(str_contains($error->getMessage(), 'timezone is Unverified'), 'An unselected timezone blocks native event authorization before any business-record publication');
    }
    expect((int) $service->store()->one('SELECT COUNT(*) AS n FROM client')['n'] === 0 && (int) $service->store()->one('SELECT COUNT(*) AS n FROM invoice')['n'] === 0, 'All connection/timezone tests and failed preflight leave destination business tables empty');
    $service->confirmSourceTimezone($profile['source_id'], 'UTC');
    rejects(fn () => $service->createRun($runInput + ['mode' => 'archive'], 1), 'Destination writes require backup and explicit confirmation');
    rejects(fn () => $service->createRun($runInput + ['mode' => 'archive', 'invalid_date_fallback' => 'epoch', 'confirmation' => 'PRESERVE HISTORY', 'backup_verified' => true, 'backup_reference' => 'disposable fixture', 'accept_limitations' => true], 1), 'Only the explicit 1900 invalid-date policy is accepted');
    $dry = $service->createRun($runInput + ['mode' => 'dry_run'], 1);
    $dryPlan = json_decode($service->store()->run($dry['id'])['plan_json'], true, 128, JSON_THROW_ON_ERROR);
    expect($dryPlan['source_security_mode'] === 'standard' && $dryPlan['source_timezone'] === 'UTC' && $dryPlan['source_timezone_evidence']['confidence'] === 'administrator_verified', 'Run plans freeze source security mode and verified timezone evidence');
    expect($dryPlan['invalid_date_fallback'] === null, 'Historical dates stay strict unless the 1900 fallback is explicitly selected');
    $fallbackPreview = $service->createRun($runInput + ['mode' => 'dry_run', 'invalid_date_fallback' => '1900'], 1);
    expect(json_decode($service->store()->run($fallbackPreview['id'])['plan_json'], true, 128, JSON_THROW_ON_ERROR)['invalid_date_fallback'] === \Box\Mod\Whmcstofossbilling\Destination\NativeWriter::DATE_FALLBACK, 'Opt-in preview freezes the explicit 1900 policy in its plan');
    $service->confirmSourceTimezone($profile['source_id'], 'Asia/Kolkata');
    expect(json_decode($service->store()->run($dry['id'])['plan_json'], true, 128, JSON_THROW_ON_ERROR)['source_timezone'] === 'UTC', 'A later profile timezone override cannot rewrite an already-authorized run snapshot');
    $service->confirmSourceTimezone($profile['source_id'], 'UTC');
    $service->control($dry['id'], 'resume');
    $batches = 0;
    do {
        $request = bin2hex(random_bytes(16));
        $result = $service->batch($dry['id'], $request, 1);
        expect($service->batch($dry['id'], $request, 1) === $result, 'Committed duplicate batch returns the original result');
        if (++$batches > 200) throw new RuntimeException('Unbounded dry run');
    } while ($result['status'] === 'running');
    expect($result['status'] === 'preview_finished', 'Dry run finishes without claiming insertion');
    $store = $service->store();
    $clientTimestamp = ['id' => 999, 'email' => 'timestamp-fixture@example.invalid', 'datecreated' => '2010-01-01 05:30:00'];
    $clientRules = \Box\Mod\Whmcstofossbilling\Mapping\Profile::defaults($profile['schema'])['fields']['tblclients'];
    rejects(fn () => (new \Box\Mod\Whmcstofossbilling\Destination\NativeWriter($store, $profile['source_id'], 'tbl'))->validateClientPreview($clientTimestamp, $clientRules), 'Client DATETIME registrations cannot silently assume UTC when the source timezone is unresolved');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM client')['n'] === 0, 'Dry run creates no destination clients');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM whmcstofossbilling_archive')['n'] === 0, 'Dry run creates no historical archive records');
    $drivenPreview = $service->createRun($runInput + ['mode' => 'dry_run'], 1);
    $pausedDrive = $service->drive($drivenPreview['id'], str_repeat('e', 32), 2000, 50);
    $service->control($drivenPreview['id'], 'resume');
    expect($pausedDrive['status'] === 'paused' && $pausedDrive['batch_processed'] === 0, 'A paused run cannot be advanced by a drive request');
    $driveId = str_repeat('d', 32);
    $firstDrive = $service->drive($drivenPreview['id'], $driveId, 2000, 50);
    expect($service->drive($drivenPreview['id'], $driveId, 2000, 50) === $firstDrive, 'Repeated drive identifier returns the original result without processing again');
    expect($firstDrive['batch_processed'] > 0 && isset($firstDrive['categories'], $firstDrive['reconciliation']), 'One drive request processes batches and returns the complete status');
    $driven = $firstDrive;
    $driveSteps = 0;
    while ($driven['status'] === 'running' && ++$driveSteps < 100) {
        $driven = $service->drive($drivenPreview['id'], str_repeat('d', 30) . sprintf('%02x', $driveSteps), 2000, 50);
    }
    expect(!in_array($driven['status'], ['running', 'paused'], true), 'Repeated rate-limit-safe drive calls finish an authorized preview');
    rejects(fn () => $service->drive($drivenPreview['id'], 'not-a-drive-id'), 'Drive identifiers must be 32 lowercase hex characters');
    $advancedPreview = $service->createRun($runInput + ['mode' => 'dry_run', 'target_mode' => 'historical_native'], 1);
    $service->control($advancedPreview['id'], 'resume');
    do {
        $previewResult = $service->batch($advancedPreview['id'], bin2hex(random_bytes(16)), 50);
    } while ($previewResult['status'] === 'running');
    $previewErrors = $service->status($advancedPreview['id'])['errors'];
    expect($previewResult['status'] === 'requires_action' && in_array('tblinvoices', array_column($previewErrors, 'table_name'), true) && in_array('tblhosting', array_column($previewErrors, 'table_name'), true), 'Historical-target dry run identifies unsafe invoice and hosting eligibility before publication');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM client')['n'] === 0 && (int) $store->one('SELECT COUNT(*) AS n FROM whmcstofossbilling_snapshot')['n'] === 0, 'Advanced dry-run validation creates neither business records nor preserved-history snapshots');
    $authorized = $runInput + ['mode' => 'reference_native', 'confirmation' => 'PRESERVE HISTORY', 'backup_verified' => true, 'backup_reference' => 'disposable fixture snapshot', 'accept_limitations' => true, 'destination_exclusive' => true];
    $run = $service->createRun($authorized, 1);
    $existing = $service->existingRun($authorized);
    expect($existing['run']['id'] === $run['id'] && $existing['run']['status'] === 'paused' && $existing['differences'] === [], 'Unfinished-run lookup returns the paused run with matching saved choices');
    $savedPlan = $service->store()->run($run['id'])['plan_json'];
    $different = $service->existingRun(array_replace($authorized, ['mode' => 'archive', 'tables' => ['tblclients'], 'invalid_date_fallback' => '1900']));
    expect(in_array('execution mode', $different['differences'], true) && in_array('selected tables', $different['differences'], true) && in_array('invalid-date policy', $different['differences'], true), 'Existing-run lookup reports mode/table/date-policy differences without changing the plan');
    expect($service->store()->run($run['id'])['plan_json'] === $savedPlan && $sourceDigest() === $before, 'Existing-run lookup neither rewrites immutable history plans nor modifies the source');
    $service->control($run['id'], 'cancel');
    expect($service->existingRun($authorized) === null, 'Cancelled runs do not block or get offered as unfinished history work');
    $run = $service->createRun($authorized, 1);
    rejects(fn () => $service->createRun($authorized, 1), 'A source cannot have overlapping unfinished non-preview runs');
    $service->control($run['id'], 'resume');
    expect($service->existingRun($authorized)['run']['status'] === 'running', 'Running unfinished runs are discoverable without resuming or scheduling batches');
    $service->batch($run['id'], bin2hex(random_bytes(16)), 1);
    $service->control($run['id'], 'pause');
    rejects(fn () => $service->batch($run['id'], bin2hex(random_bytes(16)), 1), 'Paused run refuses future batches');
    // Reconstruct service: no in-memory engine state is required to resume.
    $service = new Service();
    $service->setDi($di);
    $service->control($run['id'], 'resume');
    $batches = 0;
    do {
        $result = $service->batch($run['id'], bin2hex(random_bytes(16)), 2);
        if (++$batches > 100) throw new RuntimeException('Unbounded migration');
    } while ($result['status'] === 'running');
    $status = $service->status($run['id']);
    expect($status['date_fallbacks'] === [], 'Runs without invalid dates report no substitutions');
    if ($status['errors']) {
        echo json_encode($status['errors'], JSON_PRETTY_PRINT), PHP_EOL;
    }
    expect($status['status'] === 'partial_archived', 'Run reports partial archival, never a complete billing migration');
    expect($service->existingRun($authorized) === null, 'Finished historical runs are not offered as unfinished even while previews exist');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM client')['n'] === 3, 'Clients imported natively');
    expect($store->one('SELECT created_at FROM client WHERE aid=?', ['whmcs:' . $profile['source_id'] . ':1'])['created_at'] === '2004-02-29 00:00:00', 'Original client registration date survives persistence');
    $timestampRow = $clientTimestamp + ['firstname' => 'Zone', 'lastname' => 'Fixture', 'currency' => 1, 'groupid' => 0, 'status' => 'Closed', 'notes' => '', 'credit' => '0.00'];
    foreach (['2010-01-01 05:30:00', '2010-01-01'] as $registration) {
        $timestampRow['datecreated'] = $registration;
        $store->pdo->beginTransaction();
        try {
            $writer = new \Box\Mod\Whmcstofossbilling\Destination\NativeWriter($store, $profile['source_id'], 'tbl', 'Asia/Kolkata');
            $writer->import('clients', $timestampRow, $clientRules, hash('sha256', Store::json($timestampRow)), $run['id']);
            expect($store->one('SELECT created_at FROM client WHERE aid=?', ['whmcs:' . $profile['source_id'] . ':999'])['created_at'] === '2010-01-01 00:00:00', 'Native client adapter correctly handles ' . $registration . ' as ' . (strlen($registration) === 10 ? 'an unchanged calendar date' : 'a converted application timestamp'));
        } finally {
            $store->pdo->rollBack(); // Only these extra disposable adapter assertions.
        }
    }
    $zeroDateRow = ['id' => 998, 'email' => 'zero-date-fixture@example.invalid', 'datecreated' => '0000-00-00'] + array_diff_key($timestampRow, ['id' => true, 'email' => true, 'datecreated' => true]);
    rejects(fn () => (new \Box\Mod\Whmcstofossbilling\Destination\NativeWriter($store, $profile['source_id'], 'tbl'))->import('clients', $zeroDateRow, $clientRules, hash('sha256', Store::json($zeroDateRow)), $run['id']), 'Strict writer still refuses invalid client registration dates');
    $fallbackWriter = new \Box\Mod\Whmcstofossbilling\Destination\NativeWriter($store, $profile['source_id'], 'tbl', 'UTC', \Box\Mod\Whmcstofossbilling\Destination\NativeWriter::DATE_FALLBACK);
    $store->pdo->beginTransaction();
    try {
        expect($fallbackWriter->import('clients', $zeroDateRow, $clientRules, hash('sha256', Store::json($zeroDateRow)), $run['id']) === 'date_fallback', 'Opt-in writer reports a date substitution instead of a failure');
        expect($fallbackWriter->dateFallbackOriginal() === '0000-00-00', 'Original invalid value remains available for the audit record');
        expect($store->one('SELECT created_at FROM client WHERE aid=?', ['whmcs:' . $profile['source_id'] . ':998'])['created_at'] === \Box\Mod\Whmcstofossbilling\Destination\NativeWriter::DATE_FALLBACK, 'Invalid client registration date imports with the explicit 1900 sentinel');
    } finally {
        $store->pdo->rollBack();
    }
    expect((int) $store->one("SELECT COUNT(*) AS n FROM product WHERE active=0 AND hidden=1 AND setup='manual'")['n'] === 4, 'Products and add-ons remain disabled, hidden and manually provisioned');
    $productId = $store->one("SELECT destination_id FROM whmcstofossbilling_identity WHERE source_id=? AND table_name='tblproducts' AND source_key='1' AND destination_entity='product'", [$profile['source_id']])['destination_id'];
    $product = $store->one('SELECT * FROM product WHERE id=?', [$productId]);
    $payment = $store->one('SELECT * FROM product_payment WHERE id=?', [$product['product_payment_id']]);
    expect($payment['m_price'] === '10.00' && $payment['q_price'] === '27.00' && $payment['a_price'] === '100.00' && $payment['bia_price'] === '180.00' && $payment['tria_price'] === '250.00', 'Native base-currency recurring cycle prices imported exactly');
    expect((int) $payment['b_enabled'] === 0 && $payment['m_setup_price'] === '1.00' && $payment['tria_setup_price'] === '6.00', 'Disabled-cycle sentinel and cycle-specific setup fees preserved');
    $config = json_decode($product['config'], true);
    $server = $store->one('SELECT * FROM service_hosting_server WHERE id=?', [$config['server_id']]);
    $plan = $store->one('SELECT * FROM service_hosting_hp WHERE id=?', [$config['hosting_plan_id']]);
    expect($plan['name'] === 'legacy_plan' && $server['manager'] === 'Whm', 'Explicit hosting package and unambiguous product/server group relationship imported');
    expect((int) $server['active'] === 0 && $server['username'] === null && $server['password'] === null && $server['accesshash'] === null, 'Server metadata has no usable credentials and remains inactive');
    $eur = $store->one("SELECT * FROM whmcstofossbilling_price WHERE product_id=? AND currency='EUR'", [$productId]);
    expect((int) $eur['native_applied'] === 0 && json_decode($eur['prices_json'], true)['1M']['amount'] === '9.00', 'Independent EUR price matrix preserved without replacing native USD prices');
    $addon = $store->one('SELECT * FROM product WHERE is_addon=1');
    $addonPayment = $store->one('SELECT * FROM product_payment WHERE id=?', [$addon['product_payment_id']]);
    expect($addonPayment['type'] === 'once' && $addonPayment['once_price'] === '5.00' && $addonPayment['once_setup_price'] === '2.00', 'Add-on one-time price and setup fee imported');
    expect(in_array((int) $addon['id'], json_decode($product['addons'], true), true), 'Native add-on is linked to its original parent product');
    expect((int) $store->one("SELECT COUNT(*) AS n FROM product_payment WHERE type='free'")['n'] === 1, 'Free product payment mode preserved');
    expect((int) $store->one("SELECT COUNT(*) AS n FROM product_payment WHERE type='once' AND once_price='50.00' AND once_setup_price='3.00'")['n'] === 1, 'One-time product price and setup fee preserved');
    expect((int) $store->one("SELECT COUNT(*) AS n FROM support_ticket WHERE status='closed' AND created_at='2010-01-01 00:00:00'")['n'] === 1, 'Closed historical ticket imported with original opening date');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM support_ticket_message')['n'] === 2 && (int) $store->one('SELECT COUNT(*) AS n FROM support_ticket_note')['n'] === 1, 'Opening message, staff reply and private internal note are separate native records');
    expect(!str_contains(Store::json($store->all('SELECT content FROM support_ticket_message')), 'Private internal note'), 'Internal note never becomes a customer-visible message');
    $email = $store->one('SELECT * FROM activity_client_email');
    expect($email['created_at'] === '2010-01-04 00:00:00' && $email['content_html'] === null && !str_contains($email['content_text'], 'DO_NOT_ARCHIVE'), 'Historical email date preserved and native body rendered as redacted text');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM email_queue')['n'] === 0, 'Historical records do not enqueue any customer emails');
    foreach (['invoice', 'transaction', 'client_balance', 'client_order', 'service_hosting', 'service_domain'] as $table) {
        expect((int) $store->one("SELECT COUNT(*) AS n FROM `$table`")['n'] === 0, $table . ' unsafe native category remains untouched');
    }
    $archives = $store->all('SELECT payload FROM whmcstofossbilling_archive');
    expect(!str_contains(Store::json($archives), '"password":"DO_NOT_EXTRACT"') && !str_contains(Store::json($archives), 'DO_NOT_ARCHIVE'), 'Excluded secrets and recognized free-text credentials are not retained');
    $invoice = $store->one("SELECT payload FROM whmcstofossbilling_archive WHERE table_name='tblinvoices' AND source_key='1'");
    expect(json_decode($invoice['payload'], true)['total'] === '115.00', 'Exact historical invoice amount retained in archive');
    $payment = $store->one("SELECT payload FROM whmcstofossbilling_archive WHERE table_name='tblaccounts' AND source_key='1'");
    expect(json_decode($payment['payload'], true)['date'] === '2010-01-05 12:00:00', 'Original payment date retained');
    $second = $service->createRun($authorized, 1);
    $service->control($second['id'], 'resume');
    do {
        $result = $service->batch($second['id'], bin2hex(random_bytes(16)), 50);
    } while ($result['status'] === 'running');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM client')['n'] === 3, 'Repeated import does not duplicate clients');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM product')['n'] === 4 && (int) $store->one('SELECT COUNT(*) AS n FROM product_payment')['n'] === 4, 'Repeated import does not duplicate products, add-ons or pricing aggregates');
    $repeated = $service->status($second['id']);
    expect(array_sum(array_column($repeated['categories'], 'archived')) === 0, 'Unchanged archived rows are skipped on repeated imports');
    // Exporters contain identities and counts, not source payloads, emails or passwords.
    ob_start();
    (new \Box\Mod\Whmcstofossbilling\Report\Exporter($service))->response($run['id'], 'json')->sendContent();
    $export = ob_get_clean();
    expect(!str_contains($export, 'ada@example.invalid') && !str_contains($export, 'DO_NOT_'), 'JSON reports omit private payloads and secrets');
    expect(count(json_decode($export, true, 128, JSON_THROW_ON_ERROR)['records']) > 0, 'JSON report contains actual per-record dispositions');
    $legacy = $service->archive(['source_id' => $profile['source_id'], 'legacy_kind' => 'invoice', 'legacy_reference' => 'LEGACY-001']);
    expect(count($legacy['records']) === 1 && $legacy['records'][0]['source_key'] === '1', 'Original textual invoice number is searchable even when native numbering cannot represent it');
    $financial = $service->createRun(array_replace($authorized, ['mode' => 'historical_native']), 1);
    $service->control($financial['id'], 'resume');
    do {
        $result = $service->batch($financial['id'], bin2hex(random_bytes(16)), 50);
    } while ($result['status'] === 'running');
    expect($result['status'] === 'requires_action', 'Unsupported invoices/payments remain failures, never a complete migration');
    $invoice = $store->one('SELECT * FROM invoice');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM invoice')['n'] === 1 && $invoice['nr'] === '003' && $invoice['currency'] === 'EUR', 'Only safely representable invoice publishes natively with its numeric legacy number and currency');
    expect($invoice['created_at'] === '2011-01-01 00:00:00' && $invoice['paid_at'] === '2011-01-05 12:00:00', 'Native invoice preserves original creation and payment dates');
    $item = $store->one('SELECT * FROM invoice_item');
    expect($item['task'] === 'void' && $item['status'] === 'executed' && (int) $invoice['approved'] === 0, 'Native historical invoice items are non-actionable and invoice is not approved for billing');
    $transaction = $store->one('SELECT * FROM transaction');
    expect($transaction['txn_id'] === 'EUR-EXACT' && $transaction['created_at'] === '2011-01-05 12:00:00' && $transaction['status'] === 'processed' && $transaction['gateway_id'] === null, 'Native historical payment retains reference/date and cannot enter gateway processing queue');
    $assessment = $service->status($financial['id'])['reconciliation'];
    expect($assessment['status'] === 'exceptions', 'Financial assessment discloses unsupported/missing native data and fee limitations');
    $values = array_column($assessment['totals_by_currency']['invoice_value'], null, 'currency');
    expect($values['EUR']['source_total'] === '22.00' && $values['EUR']['native_total'] === '22.00' && $values['USD']['source_total'] === '165.00' && $values['USD']['native_total'] === null, 'Exact invoice totals reconcile separately by currency without disguising missing USD native records');
    expect((int) $store->one("SELECT COUNT(*) AS n FROM invoice WHERE status='unpaid'")['n'] === 0, 'Unpaid historical invoice is not exposed to core expiry deletion');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM email_queue')['n'] === 0, 'Native financial publication sends no email');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM client_balance')['n'] === 2, 'Only original credit movements are posted, with no duplicate opening balance');
    expect($store->one('SELECT CAST(SUM(amount) AS DECIMAL(18,2)) AS amount FROM client_balance')['amount'] === '25.00', 'Native credit ledger reconciles exactly to source closing balance');
    expect($store->one('SELECT MIN(created_at) AS first FROM client_balance')['first'] === '2009-12-01 00:00:00', 'Credit ledger retains original transaction dates');
    $balances = array_column($assessment['totals_by_currency']['balance'], null, 'currency');
    expect($balances['USD']['source_total'] === '30.00' && $balances['USD']['native_total'] === '25.00', 'USD closing-balance discrepancy is reported without forcing an adjustment');
    $creditFailure = $store->one("SELECT * FROM whmcstofossbilling_credit_result WHERE run_id=? AND source_key='3'", [$financial['id']]);
    expect($creditFailure['status'] === 'failed' && (int) $store->one("SELECT COUNT(*) AS n FROM client_balance WHERE client_id=(SELECT destination_id FROM whmcstofossbilling_identity WHERE source_id=? AND table_name='tblclients' AND source_key='3' AND destination_entity='client')", [$profile['source_id']])['n'] === 0, 'Mismatched source credit history publishes no partial ledger or artificial opening balance');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM transaction WHERE invoice_id=?', [$invoice['id']])['n'] === 3, 'Multiple partial payments and a refund link to the same historical invoice');
    $paymentTotals = array_column($assessment['totals_by_currency']['received'], null, 'currency');
    $refundTotals = array_column($assessment['totals_by_currency']['refunded'], null, 'currency');
    expect($paymentTotals['EUR']['source_total'] === '22.00' && $paymentTotals['EUR']['native_total'] === '22.00' && $refundTotals['EUR']['source_total'] === '2.00' && $refundTotals['EUR']['native_total'] === '2.00', 'Partial payment/refund totals match separately in EUR');
    $guards = new Pimple\Container();
    $guards['db'] = new class($store) {
        public function __construct(private Store $store) {}
        public function getAll(string $sql, array $values = []): array { return $this->store->all($sql, $values); }
        public function find(string $type, string $conditions, array $values = []): array {
            if ($type !== 'Invoice') throw new RuntimeException('Unexpected core query type');
            return $this->store->all('SELECT * FROM invoice WHERE ' . $conditions, $values);
        }
    };
    $guards['logger'] = new class { public function info(...$data): void {} };
    $itemService = new \Box\Mod\Invoice\ServiceInvoiceItem();
    $itemService->setDi($guards);
    $transactionService = new \Box\Mod\Invoice\ServiceTransaction();
    $transactionService->setDi($guards);
    $invoiceService = new \Box\Mod\Invoice\Service();
    $invoiceService->setDi($guards);
    $guards['mod_service'] = $guards->protect(static function (string $module, string $sub = '') use ($itemService) {
        if (strtolower($module) === 'invoice' && $sub === 'InvoiceItem') return $itemService;
        throw new RuntimeException('Unexpected operational service call in isolation test');
    });
    expect($itemService->getAllNotExecutePaidItems() === [], 'Actual core paid-item selector excludes imported executed historical lines');
    expect($transactionService->getReceived() === [], 'Actual core transaction selector excludes imported processed payments');
    expect($invoiceService->findAllUnpaid() === [] && $invoiceService->findUnpaidOlderThan(1) === [], 'Actual core credit-payment and old-unpaid cleanup selectors find no actionable imported invoices');
    expect($invoiceService->doBatchPaidInvoiceActivation() && $transactionService->processReceivedATransactions(), 'Actual core activation/payment processing batches perform no operational calls against imported history');
    $hosting = $store->one('SELECT * FROM service_hosting');
    $order = $store->one('SELECT *,CAST(price AS DECIMAL(18,2)) AS exact_price FROM client_order');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM service_hosting')['n'] === 1 && $hosting['username'] === 'olduser' && $hosting['ip'] === '198.51.100.50', 'Only a safely inactive historical hosting service is imported with existing account identity');
    expect($order['status'] === 'canceled' && $order['period'] === '1M' && $order['exact_price'] === '7.50' && $order['created_at'] === '2008-01-01 00:00:00' && $order['expires_at'] === '2009-01-01 00:00:00', 'Historical hosting order preserves original billing cycle, dates and service-specific price');
    expect(json_decode($order['config'], true)['legacy_status'] === 'Terminated' && $hosting['pass'] === null, 'Original hosting status is traceable and no account password is imported');
    $ormConfig = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/Order/Entity'], cache: new \Symfony\Component\Cache\Adapter\ArrayAdapter());
    $ormConfig->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
    if (PHP_VERSION_ID >= 80400) $ormConfig->enableNativeLazyObjects(true);
    else {
        $ormConfig->setProxyDir(dirname(__DIR__) . '/.local/doctrine-proxies');
        $ormConfig->setProxyNamespace('WhmcstofossbillingTestProxies');
    }
    $dbal = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $fixtureHost, 'port' => $db['port'] ?? 3306, 'dbname' => $destinationDb, 'user' => $db['user'], 'password' => $db['password'], 'charset' => 'utf8mb4']);
    $em = new \Doctrine\ORM\EntityManager($dbal, $ormConfig);
    $repository = $em->getRepository(\Box\Mod\Order\Entity\Order::class);
    expect($repository->getExpired() === [] && $repository->getSoonExpiringActiveOrders(14) === [] && $repository->getStaleUnpaid(1) === [], 'Actual core order selectors exclude historical canceled services from suspension, renewal invoicing and unpaid cleanup');
    $financialAgain = $service->createRun(array_replace($authorized, ['mode' => 'historical_native']), 1);
    $service->control($financialAgain['id'], 'resume');
    do {
        $result = $service->batch($financialAgain['id'], bin2hex(random_bytes(16)), 50);
    } while ($result['status'] === 'running');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM invoice')['n'] === 1 && (int) $store->one('SELECT COUNT(*) AS n FROM transaction')['n'] === 3 && (int) $store->one('SELECT COUNT(*) AS n FROM client_balance')['n'] === 2, 'Repeated historical native runs do not duplicate invoices, payments or credit history');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM service_hosting')['n'] === 1 && (int) $store->one('SELECT COUNT(*) AS n FROM client_order')['n'] === 1, 'Repeated historical native run does not duplicate hosting service/order aggregates');
    $destination->beginTransaction();
    try {
        $store->execute('UPDATE product_payment SET m_price=? WHERE id=?', ['999.00', $product['product_payment_id']]);
        $sourceProduct = $store->one("SELECT payload,checksum FROM whmcstofossbilling_snapshot WHERE run_id=? AND table_name='tblproducts' AND source_key='1'", [$financialAgain['id']]);
        rejects(fn () => (new \Box\Mod\Whmcstofossbilling\Destination\ProductImporter($store, $profile['source_id'], 'tbl'))->import('products', json_decode($sourceProduct['payload'], true), $sourceProduct['checksum'], $financialAgain['id']), 'Manual destination pricing change is detected through aggregate child checksums');
    } finally {
        $destination->rollBack();
    }
    ob_start();
    (new \Box\Mod\Whmcstofossbilling\Report\Exporter($service))->response($financial['id'], 'csv')->sendContent();
    $csv = ob_get_clean();
    $csvRows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", trim($csv))));
    expect(count(array_unique(array_map('count', $csvRows))) === 1 && count($csvRows[0]) === 18 && str_contains($csv, 'financial_snapshot'), 'CSV reports have consistent columns and actual currency-specific assessment totals');
    $cancel = $service->createRun($runInput + ['mode' => 'dry_run'], 1);
    $service->control($cancel['id'], 'cancel');
    rejects(fn () => $service->control($cancel['id'], 'resume'), 'Cancelled runs cannot restart accidentally');
    expect($sourceDigest() === $before, 'Synthetic source data remains byte-for-byte unchanged');
    // Intentional synthetic changes are made by the FIXTURE ADMIN, never by the source Reader.
    $admin->exec("UPDATE `$sourceDb`.tblclients SET firstname='Changed fixture name' WHERE id=1");
    $changedSource = $sourceDigest();
    foreach ($selection as $name) $service->analyze($profile['source_id'], $name);
    $incremental = $service->createRun($authorized, 1);
    $service->control($incremental['id'], 'resume');
    do {
        $result = $service->batch($incremental['id'], bin2hex(random_bytes(16)), 50);
    } while ($result['status'] === 'running');
    expect($result['status'] === 'requires_action', 'Changed native source row requires explicit review instead of overwrite');
    expect($store->one('SELECT first_name FROM client WHERE aid=?', ['whmcs:' . $profile['source_id'] . ':1'])['first_name'] === 'Ada', 'Existing native destination record preserved on source change');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM whmcstofossbilling_archive_revision')['n'] === 1, 'Prior archive revision retained when source changes');
    for ($retry = 0; $retry < 5; ++$retry) $service->retry($incremental['id']);
    $exception = $store->one("SELECT attempts FROM whmcstofossbilling_attempt WHERE run_id=? AND table_name='tblclients' AND source_key='1'", [$incremental['id']]);
    expect((int) $exception['attempts'] === 5, 'Explicit failed-record retries are bounded to five total attempts');
    expect($sourceDigest() === $changedSource, 'Incremental scan/retry never changes the synthetic source');
    $frozen = $store->one("SELECT payload FROM whmcstofossbilling_snapshot WHERE run_id=? AND table_name='tblclients' AND source_key='1'", [$financial['id']]);
    expect(json_decode($frozen['payload'], true)['firstname'] === 'Ada', 'Per-run source snapshot survives later runs changing the latest archive');
    $beforeTotals = $service->status($financial['id'])['reconciliation']['totals_by_currency'];
    $store->execute("UPDATE whmcstofossbilling_reconcile_state SET after_id=0,status='scanning' WHERE run_id=?", [$financial['id']]);
    $reconciler = new \Box\Mod\Whmcstofossbilling\Finance\Reconciler($store);
    while (!$reconciler->batch($store->run($financial['id']), 2)) {}
    expect($reconciler->summary($financial['id'])['totals_by_currency'] === $beforeTotals, 'Restarting reconciliation neither mixes later source snapshots nor double-counts totals');
    $store->execute('UPDATE invoice SET currency=? WHERE id=?', ['USD', $invoice['id']]);
    try {
        $store->execute("UPDATE whmcstofossbilling_reconcile_state SET after_id=0,status='scanning' WHERE run_id=?", [$financial['id']]);
        while (!$reconciler->batch($store->run($financial['id']), 2)) {}
        $conflict = $store->one("SELECT status,native_metrics FROM whmcstofossbilling_assessment WHERE run_id=? AND table_name='tblinvoices' AND source_key='3'", [$financial['id']]);
        $conflictTotals = array_column($reconciler->summary($financial['id'])['totals_by_currency']['invoice_value'], null, 'currency');
        expect($conflict['status'] === 'currency_conflict' && json_decode($conflict['native_metrics'], true)['_actual_currency'] === 'USD' && $conflictTotals['EUR']['native_total'] === null, 'Destination currency drift is reported and never mixed into the source currency bucket');
    } finally {
        $store->execute('UPDATE invoice SET currency=? WHERE id=?', ['EUR', $invoice['id']]);
        $store->execute("UPDATE whmcstofossbilling_reconcile_state SET after_id=0,status='scanning' WHERE run_id=?", [$financial['id']]);
        while (!$reconciler->batch($store->run($financial['id']), 2)) {}
    }
    // Additional intentionally oversized SYNTHETIC source fixtures, created only
    // after the unchanged-source assertions above. These are not live records.
    $admin->exec("ALTER TABLE `$sourceDb`.tblnotes MODIFY note MEDIUMTEXT");
    $insertNote = $admin->prepare("INSERT INTO `$sourceDb`.tblnotes VALUES (?,1,'2010-01-04 00:00:00',?)");
    $insertNote->execute([2, str_repeat('A', 2097153)]);
    $medium = str_repeat('B', 524288);
    for ($note = 3; $note <= 22; ++$note) $insertNote->execute([$note, $medium]);
    $beforeLargeRead = $sourceDigest();
    $reader = new Reader($options);
    $largeSchema = $reader->inspect();
    $largePage = $reader->page('tblnotes', $largeSchema['tblnotes'], '0', '2', 50);
    $oversized = $largePage['rows'][1];
    expect((int) $oversized['__wm_oversized'] === 1 && $oversized['note'] === null && (string) $oversized['id'] === '2', 'Oversized source body is rejected on the SELECT before entering PHP, with its original ID retained');
    $largeRules = \Box\Mod\Whmcstofossbilling\Mapping\Profile::defaults($largeSchema)['fields']['tblnotes'];
    rejects(fn () => (new \Box\Mod\Whmcstofossbilling\Archive\Repository($store))->preserve($profile['source_id'], 'tblnotes', '2', $oversized, $largeSchema['tblnotes'], $largeRules, $financial['id']), 'An oversized extraction sentinel cannot become a null/truncated archive record');
    $page = $reader->page('tblnotes', $largeSchema['tblnotes'], '2', '22', 50);
    expect(count($page['rows']) > 0 && count($page['rows']) < 20 && !$page['exhausted'], 'Byte-limited page does not falsely report end-of-table when fewer than 50 rows fit');
    $read = count($page['rows']);
    $steps = 0;
    while (!$page['exhausted']) {
        $last = (string) $page['rows'][array_key_last($page['rows'])]['id'];
        $page = $reader->page('tblnotes', $largeSchema['tblnotes'], $last, '22', 50);
        $read += count($page['rows']);
        if (++$steps > 30) throw new RuntimeException('Unbounded byte-budget extraction');
    }
    expect($read === 20, 'Adaptive byte-budget pagination reads every eligible large record without skipping');
    unset($reader, $largePage, $page);
    expect($sourceDigest() === $beforeLargeRead, 'Size probes and bounded source SELECTs never modify oversized/large source fixtures');
    $largeProfile = $service->testConnection($options);
    $service->analyze($largeProfile['source_id'], 'tblnotes');
    $largeRun = $service->createRun([
        'source_id' => $largeProfile['source_id'], 'tables' => ['tblnotes'], 'mode' => 'archive',
        'confirmation' => 'PRESERVE HISTORY', 'backup_verified' => true,
        'backup_reference' => 'disposable large-record fixture', 'accept_limitations' => true,
    ], 1);
    $service->control($largeRun['id'], 'resume');
    $steps = 0;
    do {
        $result = $service->batch($largeRun['id'], bin2hex(random_bytes(16)), 50);
        if (++$steps > 40) throw new RuntimeException('Unbounded large-record migration');
    } while ($result['status'] === 'running');
    $largeStatus = $service->status($largeRun['id']);
    expect($largeStatus['status'] === 'requires_action' && (int) $largeStatus['categories'][0]['processed'] === 22 && (int) $largeStatus['categories'][0]['failed'] === 1, 'Actual resumable engine processes every byte-limited record and reports the oversized record as failed');
    expect((int) $store->one("SELECT COUNT(*) AS n FROM whmcstofossbilling_snapshot WHERE run_id=? AND table_name='tblnotes'", [$largeRun['id']])['n'] === 21, 'Oversized record is not archived as fabricated null data; all other records are preserved');
    expect($sourceDigest() === $beforeLargeRead, 'Large-record migration leaves the synthetic source unchanged');
    expect($service->destinationStatus()['ready'], 'A destination containing only migration-owned business rows remains resumable without a backup reference');
    $store->pdo->beginTransaction();
    try {
        $store->execute("DELETE FROM whmcstofossbilling_identity WHERE destination_entity='client' AND source_id=?", [$profile['source_id']]);
        expect(!$service->destinationStatus()['ready'], 'Unrelated/untracked clients block the fresh-install workflow');
        rejects(fn () => \Box\Mod\Whmcstofossbilling\Destination\FreshCheck::assertReady($store), 'Fresh-install guard fails closed without deleting or changing business data');
    } finally { $store->pdo->rollBack(); }
    // Export only SYNTHETIC fixture rows, then consume the dump without executing it.
    $rocksPreamble = <<<'SQL'
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;
/*!50717 SELECT COUNT(*) INTO @rocksdb_has_p_s_session_variables FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'performance_schema' AND TABLE_NAME = 'session_variables' */;
/*!50717 SET @rocksdb_get_is_supported = IF (@rocksdb_has_p_s_session_variables, 'SELECT COUNT(*) INTO @rocksdb_is_supported FROM performance_schema.session_variables WHERE VARIABLE_NAME=\'rocksdb_bulk_load\'', 'SELECT 0') */;
/*!50717 PREPARE s FROM @rocksdb_get_is_supported */;
/*!50717 EXECUTE s */;
/*!50717 DEALLOCATE PREPARE s */;
/*!50717 SET @rocksdb_enable_bulk_load = IF (@rocksdb_is_supported, 'SET SESSION rocksdb_bulk_load = 1', 'SET @rocksdb_dummy_bulk_load = 0') */;
/*!50717 PREPARE s FROM @rocksdb_enable_bulk_load */;
/*!50717 EXECUTE s */;
/*!50717 DEALLOCATE PREPARE s */;
SQL;
    $rocksEpilogue = <<<'SQL'
/*!50112 SET @disable_bulk_load = IF (@is_rocksdb_supported, 'SET SESSION rocksdb_bulk_load = @old_rocksdb_bulk_load', 'SET @dummy_rocksdb_bulk_load = 0') */;
/*!50112 PREPARE s FROM @disable_bulk_load */;
/*!50112 EXECUTE s */;
/*!50112 DEALLOCATE PREPARE s */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
SQL;
    $dump = "-- Synthetic WHMCS SQL source; never executed\n" . $rocksPreamble . "\n";
    foreach (['tblclients', 'tblproducts', 'tblhosting', 'tblinvoices', 'tblinvoiceitems', 'tblaccounts', 'tblconfiguration', 'tblcurrencies', 'tblclientgroups'] as $name) {
        $definition = $admin->query("SHOW CREATE TABLE `$sourceDb`.`$name`")->fetch();
        $dump .= $definition['Create Table'] . ";\n";
        // Deliberately reverse physical row order; preparation must index by key.
        foreach ($admin->query("SELECT * FROM `$sourceDb`.`$name` ORDER BY id DESC") as $record) {
            $dump .= "INSERT INTO `$name` VALUES (" . implode(',', array_map(static fn ($value) => $value === null ? 'NULL' : $admin->quote((string) $value), array_values($record))) . ");\n";
        }
    }
    $dump .= $rocksEpilogue . "\n";
    $upload = $service->backup('begin', ['filename' => 'synthetic.sql', 'size' => strlen($dump), 'source_timezone' => 'UTC'], 1);
    $dumpJobId = $upload['backup_id'];
    for ($offset = 0; $offset < strlen($dump); $offset += $upload['chunk_size']) $service->backup('chunk', ['backup_id' => $dumpJobId, 'offset' => $offset, 'data' => base64_encode(substr($dump, $offset, $upload['chunk_size']))], 1);
    do { $preparedDump = $service->backup('prepare', ['backup_id' => $dumpJobId], 1); } while ($preparedDump['phase'] !== 'ready');
    $dumpSource = $service->backup('source', ['backup_id' => $dumpJobId], 1);
    expect($dumpSource['source_kind'] === 'sql_file' && $dumpSource['version'] === '8.10.1' && !str_contains(Store::json($dumpSource), $password), 'SQL source profile uses the shared mapper and returns no source password or private file path');
    $dumpTables = [];
    foreach ($dumpSource['schema'] as $name => $definition) if ($definition['classification'] === 'archive') { $service->analyze($dumpSource['source_id'], $name); $dumpTables[] = $name; }
    $dumpInput = ['source_id' => $dumpSource['source_id'], 'tables' => $dumpTables, 'mode' => 'archive', 'confirmation' => 'PRESERVE HISTORY', 'accept_limitations' => true];
    $dumpRun = $service->createRun($dumpInput, 1);
    expect($dumpRun['plan']['source_security_mode'] === 'sql_file' && $dumpRun['plan']['source_backup_id'] === $dumpJobId, 'SQL run freezes the private backup identity without a MySQL connection or backup-reference requirement');
    rejects(fn () => $service->backup('remove', ['backup_id' => $dumpJobId, 'confirmation' => 'REMOVE PRIVATE BACKUP'], 1), 'Private SQL files required by unfinished runs cannot be removed');
    $service->control($dumpRun['id'], 'resume');
    $dumpBatches = 0;
    do {
        $request = bin2hex(random_bytes(16));
        $dumpResult = $service->batch($dumpRun['id'], $request, 50);
        if (++$dumpBatches > 200) throw new RuntimeException('Unbounded SQL fixture engine');
    } while ($dumpResult['status'] === 'running');
    $dumpClient = $store->one("SELECT payload FROM whmcstofossbilling_archive WHERE source_id=? AND table_name='tblclients' AND source_key='1'", [$dumpSource['source_id']]);
    expect(json_decode($dumpClient['payload'], true)['firstname'] === 'Changed fixture name', 'Actual migration engine archives rows read from an encrypted SQL file');
    expect((int) $store->one('SELECT COUNT(*) AS n FROM client')['n'] === 3 && $sourceDigest() === $beforeLargeRead, 'SQL archival neither creates native clients nor changes the original source');
    rejects(fn () => $service->createRun(array_replace($dumpInput, ['mode' => 'reference_native']), 1), 'A different SQL source cannot duplicate native records from an existing migration');
    // A second independently named disposable destination proves native SQL-file
    // imports into a fresh installation without erasing any first-fixture history.
    $sqlDestinationDb = 'wm_test_destination_' . bin2hex(random_bytes(5));
    $admin->exec("CREATE DATABASE `$sqlDestinationDb` CHARACTER SET utf8mb4");
    $admin->exec("USE `$sqlDestinationDb`");
    foreach ($required as $table) {
        $definition = $admin->query('SHOW CREATE TABLE `' . $db['name'] . '`.`' . $table . '`')->fetch();
        $admin->exec($definition['Create Table']);
    }
    $admin->exec("INSERT INTO currency (code,is_default,conversion_rate) VALUES ('USD',1,1.000000)");
    $sqlPdo = new PDO('mysql:host=' . $fixtureHost . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $sqlDestinationDb . ';charset=utf8mb4', $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $sqlDi = new Pimple\Container();
    $sqlDi['dbal'] = new class($sqlPdo) {
        public function __construct(private PDO $pdo) {}
        public function getNativeConnection(): PDO { return $this->pdo; }
    };
    $sqlService = new Service(); $sqlService->setDi($sqlDi); $sqlService->install();
    $sqlFingerprint = $sqlService->store()->verifyDestination()['fingerprint'];
    $sqlSource = $sqlService->backup('source', ['backup_id' => $dumpJobId], 1);
    foreach (['tblclients', 'tblcurrencies', 'tblclientgroups'] as $name) $sqlService->analyze($sqlSource['source_id'], $name);
    $sqlNative = $sqlService->createRun(['source_id' => $sqlSource['source_id'], 'tables' => ['tblclients'], 'mode' => 'reference_native', 'confirmation' => 'PRESERVE HISTORY', 'accept_limitations' => true], 1);
    $sqlService->control($sqlNative['id'], 'resume');
    $steps = 0;
    do {
        $sqlResult = $sqlService->batch($sqlNative['id'], bin2hex(random_bytes(16)), 50);
        if (++$steps > 100) throw new RuntimeException('Unbounded native SQL fixture');
    } while ($sqlResult['status'] === 'running');
    expect((int) $sqlService->store()->one('SELECT COUNT(*) AS n FROM client')['n'] === 3 && $sqlService->store()->one('SELECT first_name FROM client ORDER BY id LIMIT 1')['first_name'] === 'Changed fixture name', 'Actual native migration imports SQL-file clients into an independently fresh disposable FOSSBilling destination');
    expect($sqlService->store()->verifyDestination()['fingerprint'] === $sqlFingerprint && $sqlService->store()->one('SELECT COUNT(*) AS n FROM email_queue')['n'] == 0, 'Native SQL import leaves core table structures unchanged and queues no email');
    $overwriteStore = $sqlService->store();
    $overwriteFingerprint = $overwriteStore->verifyDestination()['fingerprint'];
    $overwriteStore->execute("INSERT INTO client (id,email,first_name,last_name,status) VALUES (999,'unrelated@example.test','Unrelated','Record','active')");
    $overwriteStore->execute("INSERT INTO email_queue (id,recipient,sender,subject,content,tries,status,created_at,updated_at) VALUES (999,'queue@example.test','system@example.test','Synthetic queue','Synthetic body',0,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $blocked = $sqlService->destinationStatus($sqlSource['source_id'], 'reference_native');
    expect(!$blocked['ready'] && in_array('client', $blocked['unrelated_tables'], true) && in_array('email_queue', $blocked['unrelated_tables'], true), 'Destructive-overwrite preview reports every blocking business table');
    expect($blocked['tables']['client']['total'] === 4 && $blocked['tables']['client']['owned'] === 3 && $blocked['tables']['client']['unowned'] === 1, 'Overwrite preview separates source-owned rows from unrelated rows');
    rejects(fn () => \Box\Mod\Whmcstofossbilling\Destination\Overwrite::apply($overwriteStore), 'Destructive overwrite refuses to run outside the run-creation transaction');
    $overwriteInput = ['source_id' => $sqlSource['source_id'], 'tables' => ['tblclients'], 'mode' => 'reference_native', 'confirmation' => 'PRESERVE HISTORY', 'accept_limitations' => true];
    rejects(fn () => $sqlService->createRun($overwriteInput, 1), 'Blocking records still fail closed without an overwrite confirmation');
    rejects(fn () => $sqlService->createRun($overwriteInput + ['overwrite_acknowledged' => '1', 'overwrite_confirmation' => 'WRONG'], 1), 'A wrong destructive-overwrite phrase changes nothing');
    rejects(fn () => $sqlService->createRun($overwriteInput + ['overwrite_confirmation' => \Box\Mod\Whmcstofossbilling\Destination\Overwrite::CONFIRMATION], 1), 'The overwrite checkbox cannot be omitted');
    expect((int) $overwriteStore->one('SELECT COUNT(*) AS n FROM client')['n'] === 4 && (int) $overwriteStore->one('SELECT COUNT(*) AS n FROM email_queue')['n'] === 1, 'Rejected overwrite attempts leave every business row untouched');
    $overwriteRun = $sqlService->createRun($overwriteInput + ['overwrite_acknowledged' => '1', 'overwrite_confirmation' => \Box\Mod\Whmcstofossbilling\Destination\Overwrite::CONFIRMATION], 1);
    expect((int) $overwriteStore->one('SELECT COUNT(*) AS n FROM client')['n'] === 0 && (int) $overwriteStore->one('SELECT COUNT(*) AS n FROM email_queue')['n'] === 0, 'Confirmed overwrite deletes every blocking business row, not only untracked rows');
    expect((int) $overwriteStore->one("SELECT COUNT(*) AS n FROM whmcstofossbilling_identity WHERE destination_entity='client'")['n'] === 0, 'Confirmed overwrite clears stale source-owned identity mappings');
    expect($overwriteRun['plan']['destination_overwrite']['client'] === 4 && $overwriteRun['plan']['destination_overwrite']['email_queue'] === 1, 'The paused run records truthful overwrite counts');
    expect($overwriteStore->verifyDestination()['fingerprint'] === $overwriteFingerprint, 'Confirmed overwrite leaves FOSSBilling table structures unchanged');
    expect(str_contains($overwriteStore->one('SELECT backup_reference FROM whmcstofossbilling_run WHERE id=?', [$overwriteRun['id']])['backup_reference'], 'overwrite'), 'The run audit trail identifies the destructive reset');
    expect($service->backup('remove', ['backup_id' => $dumpJobId, 'confirmation' => 'REMOVE PRIVATE BACKUP'], 1)['removed'], 'Explicit cleanup removes terminal SQL source files without deleting imported evidence');
    $dumpJobId = null;
    expect(!$service->mapping($dumpSource['source_id'])['source_available'] && $store->one("SELECT id FROM whmcstofossbilling_archive WHERE source_id=? LIMIT 1", [$dumpSource['source_id']]) !== null, 'Removed SQL sources are marked unavailable while private archive evidence remains accessible');
    $service->uninstall();
    expect((int) $store->one('SELECT COUNT(*) AS n FROM client')['n'] === 3, 'Uninstall never deletes imported business records');
    echo "Integration suite finished. No live WHMCS connection was used.\n";
} finally {
    if ($dumpJobId !== null) (new \Box\Mod\Whmcstofossbilling\Source\Backup\Job((string) \FOSSBilling\Config::getProperty('info.salt')))->remove($dumpJobId, 1);
    // Delete only names generated by THIS test process, never user-provided database/account names.
    foreach (array_filter([$sourceDb, $destinationDb, $sqlDestinationDb]) as $name) {
        if (!preg_match('/\Awm_test_(source|destination)_[a-f0-9]{10}\z/D', $name)) {
            throw new RuntimeException('Cleanup scope validation failed.');
        }
        $admin->exec("DROP DATABASE IF EXISTS `$name`");
    }
    $admin->exec("DROP USER IF EXISTS '$user'@'localhost'");
}
