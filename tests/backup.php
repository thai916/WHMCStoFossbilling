<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Box\Mod\Whmcstofossbilling\Source\Backup\Job;
use Box\Mod\Whmcstofossbilling\Source\Backup\EncryptedFile;
use Box\Mod\Whmcstofossbilling\Source\Backup\KeyIndex;
use Box\Mod\Whmcstofossbilling\Source\DumpReader;
use Box\Mod\Whmcstofossbilling\Source\ReaderFactory;
use FOSSBilling\Config;

$salt = (string) Config::getProperty('info.salt');
$job = new Job($salt);
$ids = [];
$quote = static fn (string $value): string => "'" . str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '\\n', '\\r'], $value) . "'";
// Exact executable comments from the reported MySQL 5.7 RocksDB-hosted backup.
$rocksPreamble = <<<'SQL'
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
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
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
SQL;
$sql = "-- Synthetic WHMCS backup; never executed\n" . $rocksPreamble . "\n";
foreach (['clients', 'products', 'hosting', 'invoices', 'invoiceitems', 'accounts'] as $name) {
    $sql .= "CREATE TABLE `tbl$name` (`id` int NOT NULL,`name` varchar(255),`password` varchar(255),PRIMARY KEY (`id`)) ENGINE=InnoDB;\n";
    // Reverse physical INSERT order: the importer must index by key, not file position.
    $sql .= "INSERT INTO `tbl$name` VALUES (2,'second','secret'),(1," . $quote("Tibetan བོད་ ; quote ' slash \\") . ",'NEVER_ARCHIVE_PASSWORD');\n";
}
$sql .= "CREATE TABLE `tblconfiguration` (`id` int PRIMARY KEY,`setting` varchar(255),`value` text);\nINSERT INTO `tblconfiguration` VALUES (2,'Secret','DO_NOT_RETURN_CONFIG'),(1,'Version','8.10.1');\n" . $rocksEpilogue . "\n";
$sortedKeys = static function (string $id) use ($job): array {
    $stream = EncryptedFile::open(Job::directory($id) . '/tblclients.sorted', $job->key());
    $keys = [];
    try { while (($record = fread($stream, KeyIndex::RECORD)) !== false && strlen($record) === KeyIndex::RECORD) $keys[] = KeyIndex::key($record); }
    finally { fclose($stream); }
    return $keys;
};
$ascending = static function (array $keys): bool {
    $previous = null;
    foreach ($keys as $key) { if ($previous !== null && bccomp((string) $key, $previous, 0) <= 0) return false; $previous = (string) $key; }
    return true;
};
$zip = static function (string $data, string $name = 'backup.sql', bool $zip64 = false): string {
    $compressed = gzdeflate($data);
    $crc = crc32($data);
    $extra = $zip64 ? pack('vvPP', 1, 16, strlen($data), strlen($compressed)) : '';
    $local = pack('VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0, 8, 0, 0, $crc, $zip64 ? 0xffffffff : strlen($compressed), $zip64 ? 0xffffffff : strlen($data), strlen($name), strlen($extra)) . $name . $extra . $compressed;
    $extra = $zip64 ? pack('vvPPP', 1, 24, strlen($data), strlen($compressed), 0) : '';
    $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 45, $zip64 ? 45 : 20, 0, 8, 0, 0, $crc, $zip64 ? 0xffffffff : strlen($compressed), $zip64 ? 0xffffffff : strlen($data), strlen($name), strlen($extra), 0, 0, 0, 0, $zip64 ? 0xffffffff : 0) . $name . $extra;
    if ($zip64) {
        $record = pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, 1, 1, strlen($central), strlen($local));
        $locator = pack('VVPV', 0x07064b50, 0, strlen($local) + strlen($central), 1);
        return $local . $central . $record . $locator . pack('VvvvvVVv', 0x06054b50, 0, 0, 0xffff, 0xffff, 0xffffffff, 0xffffffff, 0);
    }
    return $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
};
$upload = static function (string $data, string $filename) use ($job, &$ids): array {
    $begin = $job->begin(['filename' => $filename, 'size' => strlen($data), 'source_timezone' => 'UTC'], 7);
    $ids[] = $id = $begin['backup_id'];
    $first = substr($data, 0, min(strlen($data), 113));
    $job->operate($id, 7, 'chunk', ['offset' => 0, 'data' => base64_encode($first)]);
    $retry = $job->operate($id, 7, 'chunk', ['offset' => 0, 'data' => base64_encode($first)]);
    expect($retry['uploaded'] === strlen($first), 'Upload retry is idempotent: ' . $filename);
    for ($offset = strlen($first); $offset < strlen($data); $offset += EncryptedFile::BLOCK) $job->operate($id, 7, 'chunk', ['offset' => $offset, 'data' => base64_encode(substr($data, $offset, EncryptedFile::BLOCK))]);
    $steps = 0;
    do {
        $progress = $job->operate($id, 7, 'prepare');
        if (++$steps > 100) throw new RuntimeException('Unbounded fixture preparation');
    } while ($progress['phase'] !== 'ready');
    return [$id, $job->read($id, 7)];
};
try {
    rejects(fn () => $job->begin(['filename' => 'missing-zone.sql', 'size' => 100], 7), 'SQL uploads require an explicitly submitted application timezone rather than inferring one');
    expect(\Box\Mod\Whmcstofossbilling\Source\Backup\Compression::combineCrc(crc32('first'), crc32('second'), 6) === crc32('firstsecond'), 'Checkpointed CRC combines independent expanded blocks correctly');
    foreach (['sql' => $sql, 'gz' => gzencode($sql), 'zip' => $zip($sql)] as $extension => $data) {
        [$id, $state] = $upload($data, 'fixture.' . $extension);
        expect($state['version'] === '8.10.1' && count($state['schema']) === 7, 'Version and required table metadata parsed: ' . $extension);
        expect($state['probe'] === 'deallocated', 'RocksDB capability probe comments are accepted only as a complete sequence: ' . $extension);
        $reader = new DumpReader(['backup_id' => $id]);
        $schema = $reader->inspect();
        expect($reader->bounds('tblclients', $schema['tblclients']) === ['count' => 2, 'maximum' => '2'], 'File-backed counts are exact: ' . $extension);
        $rows = $reader->batch('tblclients', $schema['tblclients'], '0', '2', 100);
        $keys = $sortedKeys($id);
        expect(count($rows) === 2 && $rows[0]['name'] === "Tibetan བོད་ ; quote ' slash \\" && !isset($rows[0]['password']), 'UTF-8/escaping preserved and passwords excluded: ' . $extension);
        expect($keys === ['1', '2'] && $ascending($keys) && $rows[0]['id'] === '1' && $rows[1]['id'] === '2', 'Unsorted INSERT rows are read through an ascending encrypted index: ' . $extension);
        expect(count($reader->batch('tblclients', $schema['tblclients'], '1', '2', 100)) === 1, 'File-backed cursor resumes without duplicates: ' . $extension);
        rejects(fn () => $reader->bounds('tblconfiguration', $schema['tblconfiguration']), 'Sensitive SQL tables cannot be imported: ' . $extension);
        expect(!str_contains(file_get_contents(Job::directory($id) . '/upload'), 'NEVER_ARCHIVE_PASSWORD') && !str_contains(file_get_contents(Job::directory($id) . '/state'), 'DO_NOT_RETURN_CONFIG'), 'Uploaded SQL and checkpoints are encrypted at rest: ' . $extension);
        rejects(fn () => $job->read($id, 8), 'Another administrator cannot claim the upload: ' . $extension);
        ReaderFactory::assertPlan(['kind' => 'sql_file', 'backup_id' => $id], ['source_security_mode' => 'sql_file', 'source_backup_id' => $id]);
        rejects(fn () => ReaderFactory::assertPlan(['kind' => 'sql_file', 'backup_id' => $id], ['source_security_mode' => 'sql_file', 'source_backup_id' => str_repeat('0', 32)]), 'Run plan cannot switch SQL files: ' . $extension);
    }
    rejects(fn () => $upload($sql . 'DELETE FROM tblclients;', 'malicious.sql'), 'Uploaded DML outside INSERT data is rejected, never executed');
    rejects(fn () => $upload($sql . "/*!40101 INSERT INTO tblclients VALUES (3,'hidden','secret') */;", 'hidden.sql'), 'Versioned INSERT comments are rejected rather than silently omitted');
    rejects(fn () => $upload(str_replace("TABLE_NAME = 'session_variables'", "TABLE_NAME = 'other'", $sql), 'altered-probe.sql'), 'Altered RocksDB probe comments are rejected instead of being silently skipped');
    rejects(fn () => $upload(gzencode($sql) . gzencode($sql), 'concatenated.gz'), 'Concatenated GZIP streams cannot silently omit a second backup');
    rejects(fn () => $upload("SET NAMES latin1;\n" . $sql, 'latin1.sql'), 'Unsupported dump encodings are not guessed');
    [$lateId, $late] = $upload(str_replace('PRIMARY KEY (`id`)', 'KEY `idx` (`id`)', $sql) . "ALTER TABLE `tblclients` ADD PRIMARY KEY (`id`);\nALTER TABLE `tblproducts` ADD PRIMARY KEY (`id`);\nALTER TABLE `tblhosting` ADD PRIMARY KEY (`id`);\nALTER TABLE `tblinvoices` ADD PRIMARY KEY (`id`);\nALTER TABLE `tblinvoiceitems` ADD PRIMARY KEY (`id`);\nALTER TABLE `tblaccounts` ADD PRIMARY KEY (`id`);\n", 'late-keys.sql');
    expect($late['schema']['tblclients']['classification'] === 'archive', 'phpMyAdmin-style late primary-key metadata is read without executing ALTER');
    [$defaultId, $defaultState] = $upload(str_replace('`name` varchar(255)', "`name` varchar(255) DEFAULT 'PRIMARY KEY unsigned'", $sql), 'quoted-default.sql');
    expect($defaultState['schema']['tblclients']['classification'] === 'archive' && $defaultState['tables']['tblclients']['count'] === 2, 'Quoted DEFAULT text cannot invent keys or silently exclude valid client history');
    rejects(fn () => $upload(str_replace("(2,'second','secret')", "(1,'second','secret')", $sql), 'duplicates.sql'), 'Duplicate/out-of-order keys cannot silently lose rows');
    rejects(fn () => $upload($zip($sql, '../backup.sql'), 'traversal.zip'), 'ZIP traversal member rejected without extraction');
    [$zip64Id, $zip64State] = $upload($zip($sql, 'backup.sql', true), 'zip64.zip');
    expect($zip64State['schema']['tblclients']['classification'] === 'archive', 'ZIP64 member and directory offsets are supported without extracting paths');
    $badZip = $zip($sql); $centralAt = strpos($badZip, "PK\x01\x02"); $badZip[$centralAt + 16] = chr(ord($badZip[$centralAt + 16]) ^ 1);
    rejects(fn () => $upload($badZip, 'bad-crc.zip'), 'ZIP CRC mismatch blocks source preparation');
    $corrupt = gzencode($sql); $corrupt[strlen($corrupt) - 8] = chr(ord($corrupt[strlen($corrupt) - 8]) ^ 1);
    rejects(fn () => $upload($corrupt, 'corrupt.gz'), 'Corrupted compressed data cannot become a source');
    $big = str_repeat("INSERT INTO `tblclients` VALUES ", 1); // extended INSERT remains one tuple at a time
    $rows = [];
    for ($i = 3; $i <= 3000; ++$i) $rows[] = "($i,'row','secret')";
    [$id, $state] = $upload($sql . $big . implode(',', $rows) . ';', 'extended.sql');
    $reader = new DumpReader(['backup_id' => $id]); $schema = $reader->inspect();
    $page = $reader->page('tblclients', $schema['tblclients'], '2048', '3000', 50);
    expect(count($page['rows']) === 50 && $page['rows'][0]['id'] === '2049', 'Sparse encrypted index seeks into extended INSERT rows');
    $manySql = '';
    for ($i = 3; $i <= 131075; ++$i) $manySql .= ($i === 3 ? '' : ',') . "($i,'r','secret')";
    [$multiId, $multiState] = $upload($sql . "INSERT INTO tblclients VALUES " . $manySql . ';', 'multi-run.sql');
    $manyKeys = $sortedKeys($multiId);
    expect($multiState['tables']['tblclients']['count'] === 131075 && count($manyKeys) === 131075 && $manyKeys[0] === '1' && $manyKeys[array_key_last($manyKeys)] === '131075' && $ascending($manyKeys), 'External sort merges multiple bounded runs into one ascending encrypted index');
    $multiReader = new DumpReader(['backup_id' => $multiId]);
    $multiSchema = $multiReader->inspect();
    $lastPage = $multiReader->page('tblclients', $multiSchema['tblclients'], '131074', '131075', 50);
    expect(count($lastPage['rows']) === 1 && $lastPage['rows'][0]['id'] === '131075', 'Final cursor seeks the last row after a multi-run merge');
    unset($manySql, $manyKeys, $multiReader, $lastPage);
    $padded = [];
    for ($i = 3; $i <= 9000; ++$i) $padded[] = "($i,'" . str_repeat('A', 200) . "','secret')";
    [$largeId, $largeState] = $upload(gzencode($sql . "INSERT INTO tblclients VALUES " . implode(',', $padded) . ';'), 'multi-block.sql.gz');
    expect($largeState['expanded'] > 1048576 && $largeState['tables']['tblclients']['count'] === 9000, 'Compressed multi-block input verifies checkpointed CRC and preserves every row');
    $rowsPath = Job::directory($id) . '/tblclients.rows';
    $handle = fopen($rowsPath, 'r+b'); ftruncate($handle, 8); fclose($handle);
    rejects(fn () => $reader->bounds('tblclients', $schema['tblclients']), 'Truncated prepared SQL data cannot silently lower scanned counts');
    $path = Job::directory($id) . '/tamper';
    EncryptedFile::append($path, $job->key(), str_repeat('private', 50000));
    $handle = fopen($path, 'r+b'); fseek($handle, 40); fwrite($handle, 'X'); fclose($handle);
    rejects(function () use ($path, $job): void { $stream = EncryptedFile::open($path, $job->key()); try { fread($stream, 100); } finally { fclose($stream); } }, 'Encrypted backup tampering fails authentication');
} finally {
    foreach ($ids as $id) $job->remove($id, 7);
}
echo "Backup suite finished. No SQL execution, staging database or live source connection.\n";
