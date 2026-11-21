<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Box\Mod\Whmcsmigrator\Security\SafeException;
use Box\Mod\Whmcsmigrator\Source\ConnectionFactory;
use Box\Mod\Whmcsmigrator\Source\ConnectionOptions;
use Box\Mod\Whmcsmigrator\Source\Reader;

if (!in_array('--disposable-server', $argv, true)) exit("Requires --disposable-server; initializes its OWN local MySQL datadir and never reads application DBA credentials.\n");
$mysqld = getenv('WM_MYSQLD');
if (!$mysqld || !is_file($mysqld)) throw new RuntimeException('Set WM_MYSQLD to a local MySQL 8 mysqld executable. No server configuration will be changed.');
$local = dirname(__DIR__) . '/.local';
if (!is_dir($local)) mkdir($local, 0700, true);
$directory = $local . '/connection-fixture-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
mkdir($directory . '/data');
mkdir($directory . '/tmp');
$process = null;
$admin = null;
$reader = null;
$portSocket = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($portSocket, false), ':'), 1);
fclose($portSocket);
$basedir = dirname(dirname($mysqld));
$password = bin2hex(random_bytes(24));
$stop = static function () use (&$process, &$admin, &$reader): void {
    $reader = null;
    if ($admin) {
        try { $admin->exec('SHUTDOWN'); } catch (Throwable) {}
        $admin = null;
    }
    if (is_resource($process)) {
        $deadline = microtime(true) + 10;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(100000);
        if (proc_get_status($process)['running']) proc_terminate($process);
        proc_close($process);
        $process = null;
    }
};
$start = static function (bool $tls) use ($mysqld, $directory, $basedir, $port, &$process, &$admin): void {
    $args = [$mysqld, '--no-defaults', '--basedir=' . $basedir, '--datadir=' . $directory . '/data',
        '--tmpdir=' . $directory . '/tmp', '--port=' . $port, '--bind-address=127.0.0.1,::1',
        '--mysqlx=OFF', '--skip-log-bin', '--max-connections=12', '--innodb-buffer-pool-size=32M',
        '--innodb-redo-log-capacity=32M', '--log-error=' . $directory . '/server.log',
        '--general-log=ON', '--general-log-file=' . $directory . '/queries.log'];
    if ($tls) {
        array_push($args, '--ssl-ca=' . $directory . '/ca.pem', '--ssl-cert=' . $directory . '/server.pem', '--ssl-key=' . $directory . '/server-key.pem');
    } else {
        $args[] = '--skip-ssl';
    }
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $directory . '/stdout.log', 'a'], 2 => ['file', $directory . '/stderr.log', 'a']], $pipes, $directory, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Could not start the disposable fixture server.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 25;
    do {
        try {
            $admin = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 1]);

            return;
        } catch (PDOException) {
            if (!proc_get_status($process)['running']) throw new RuntimeException('Disposable MySQL server exited; inspect its private .local fixture log.');
            usleep(100000);
        }
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Disposable MySQL server did not become ready within 25 seconds.');
};
$fails = static function (callable $operation, string $needle, string $label): void {
    try { $operation(); throw new RuntimeException('Expected connection failure: ' . $label); } catch (SafeException $error) {
        expect(str_contains($error->getMessage(), $needle) && !str_contains($error->getMessage(), 'bad-fixture-password'), $label . ' [' . $error->getMessage() . ']');
    }
};
try {
    $conf = $directory . '/openssl.cnf';
    file_put_contents($conf, "[req]\ndistinguished_name=dn\n[dn]\n[ca_ext]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server_ext]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:127.0.0.1\n");
    $crypto = ['config' => $conf, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $caKey = openssl_pkey_new($crypto);
    $caRequest = openssl_csr_new(['commonName' => 'Whmcsmigrator disposable CA'], $caKey, $crypto);
    $caCert = openssl_csr_sign($caRequest, null, $caKey, 2, $crypto + ['x509_extensions' => 'ca_ext'], 1);
    openssl_x509_export_to_file($caCert, $directory . '/ca.pem');
    $serverKey = openssl_pkey_new($crypto);
    $serverRequest = openssl_csr_new(['commonName' => 'fixture.invalid'], $serverKey, $crypto);
    $serverCert = openssl_csr_sign($serverRequest, $caCert, $caKey, 2, $crypto + ['x509_extensions' => 'server_ext'], 2);
    openssl_x509_export_to_file($serverCert, $directory . '/server.pem');
    openssl_pkey_export_to_file($serverKey, $directory . '/server-key.pem', null, $crypto);
    $init = proc_open([$mysqld, '--no-defaults', '--initialize-insecure', '--basedir=' . $basedir, '--datadir=' . $directory . '/data', '--log-error=' . $directory . '/initialize.log'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/initialize-stdout.log', 'a'], 2 => ['file', $directory . '/initialize-stderr.log', 'a']], $pipes, $directory, null, ['bypass_shell' => true]);
    fclose($pipes[0]);
    if (proc_close($init) !== 0) throw new RuntimeException('Disposable MySQL initialization failed. No installed server configuration was touched.');
    $start(true);
    $admin->exec('CREATE DATABASE wm_connection_fixture');
    $admin->exec('CREATE TABLE wm_connection_fixture.tblconfiguration (id INT PRIMARY KEY,setting VARCHAR(100),value TEXT)');
    $admin->exec("INSERT INTO wm_connection_fixture.tblconfiguration VALUES (1,'Version','8.10.1'),(2,'Timezone','Asia/Kolkata'),(3,'Secret','DO_NOT_EXTRACT')");
    $admin->exec("CREATE USER 'wm_reader'@'localhost' IDENTIFIED BY '$password'");
    $admin->exec("GRANT SELECT ON wm_connection_fixture.* TO 'wm_reader'@'localhost'");
    $admin->exec("CREATE USER 'wm_writer'@'localhost' IDENTIFIED BY '$password'");
    $admin->exec("GRANT SELECT,INSERT ON wm_connection_fixture.* TO 'wm_writer'@'localhost'");
    $admin->exec("CREATE USER 'wm_noselect'@'localhost' IDENTIFIED BY '$password'");
    $before = $admin->query('SELECT * FROM wm_connection_fixture.tblconfiguration ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $base = ['host' => '127.0.0.1', 'port' => $port, 'database' => 'wm_connection_fixture', 'username' => 'wm_reader', 'password' => $password, 'prefix' => 'tbl', 'timeout' => 3, 'security_acknowledged' => true];
    $plain = $base + ['security_mode' => 'standard', 'tls_ca' => 'D:/not-required.pem'];
    $reader = new Reader($plain);
    expect($reader->connectionStatus()['tls_active'] === false && !$reader->connectionStatus()['server_identity_verified'], 'Actual standard PDO connection needs no CA, negotiates no TLS and never claims identity verification');
    $reader = new Reader($base + ['security_mode' => 'verified_tls', 'tls_ca' => $directory . '/ca.pem']);
    expect($reader->connectionStatus()['tls_active'] && $reader->connectionStatus()['server_identity_verified'], 'Verified TLS succeeds against a disposable server with a valid private CA/IP SAN');
    $timezone = $reader->timezone(['tblconfiguration' => ['columns' => ['setting' => [], 'value' => []]]]);
    expect($timezone['detected_timezone'] === 'Asia/Kolkata' && $timezone['status'] === 'unverified' && !str_contains(json_encode($timezone), 'DO_NOT_EXTRACT'), 'Actual source timezone SELECT detects an exact candidate, returns offset evidence and never reads unrelated secret settings');
    $reader = new Reader($base + ['security_mode' => 'unverified_tls', 'tls_ca' => 'D:/not-required.pem']);
    expect($reader->connectionStatus()['tls_active'] && !$reader->connectionStatus()['server_identity_verified'], 'Unverified TLS really encrypts despite a private CA not in the default trust store');
    $fails(fn () => new Reader($base + ['security_mode' => 'verified_tls', 'tls_ca' => dirname(__DIR__) . '/certificates/cacert.pem']), 'TLS certificate validation failed', 'Untrusted verified TLS fails without downgrading to unverified TLS or plaintext');
    $fails(fn () => new Reader(array_replace($base, ['host' => 'localhost', 'security_mode' => 'verified_tls', 'tls_ca' => $directory . '/ca.pem'])), 'TLS certificate validation failed', 'Trusted CA with a mismatched hostname still fails identity verification');
    $fails(fn () => new Reader(array_replace($plain, ['password' => 'bad-fixture-password'])), 'authentication failed', 'Incorrect credentials have accurate sanitized authentication diagnostics');
    $fails(fn () => new Reader(array_replace($plain, ['username' => 'wm_writer'])), 'SELECT', 'A source account with write privileges is refused even in standard mode');
    $fails(fn () => new Reader(array_replace($plain, ['username' => 'wm_noselect'])), 'SELECT permissions', 'Missing database SELECT access has a distinct sanitized diagnostic');
    $fails(fn () => ConnectionFactory::connect(ConnectionOptions::validate(array_replace($plain, ['username' => 'root', 'password' => '', 'database' => 'wm_missing_fixture']))), 'database was not found', 'Missing database has a distinct sanitized diagnostic');
    expect($admin->query('SELECT * FROM wm_connection_fixture.tblconfiguration ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before, 'All successful/failed connection and timezone checks leave synthetic source data unchanged');
    $stop();
    $sourceConnections = [];
    $sourceQueries = [];
    foreach (file($directory . '/queries.log', FILE_IGNORE_NEW_LINES) as $line) {
        if (!preg_match('/\\A\\s*(?:\\d{4}-\\S+\\s+)?(\\d+)\\s+(Connect|Query|Prepare|Execute|Quit|Close stmt)\\s*(.*)\\z/', $line, $parts)) continue;
        [, $id, $command, $sql] = $parts;
        if ($command === 'Connect' && str_contains($sql, 'wm_reader@')) $sourceConnections[$id] = true;
        if (!isset($sourceConnections[$id]) || !in_array($command, ['Query', 'Prepare', 'Execute'], true)) continue;
        $sourceQueries[] = $sql;
        if (!preg_match('/\\A(?:SELECT\\b|SHOW\\b|START TRANSACTION READ ONLY\\z|ROLLBACK\\z)/i', $sql)) throw new RuntimeException('Source-query audit found a non-read-only statement. No raw SQL was disclosed.');
    }
    expect(count($sourceQueries) >= 10 && str_contains(implode("\n", $sourceQueries), 'START TRANSACTION READ ONLY'), 'Actual MySQL query log proves the source reader only issued SELECT/SHOW safety metadata, READ ONLY transaction start and rollback; no modifying statements or SET');
    $start(false);
    foreach (['verified_tls', 'unverified_tls'] as $mode) {
        $fails(fn () => new Reader($base + ['security_mode' => $mode, 'tls_ca' => $directory . '/ca.pem']), 'does not advertise TLS', $mode . ' refuses a TLS-disabled server before sending credentials, without silent downgrade');
    }
    $reader = new Reader($plain);
    expect($reader->connectionStatus()['tls_active'] === false, 'Explicit standard mode still works when the disposable server has TLS disabled');
    echo "Disposable connection suite finished. No live WHMCS or installed MySQL settings were used.\n";
} finally {
    $stop();
    // Remove only this exact randomly named fixture tree below this module.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($directory);
}
