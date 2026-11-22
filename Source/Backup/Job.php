<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source\Backup;

use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Security\Vault;

final class Job
{
    public const MAX_UPLOAD = 68719476736; // 64 GiB; bounded disk, not PHP upload_max_filesize.
    public const MAX_EXPANDED = 137438953472;
    private string $key;
    private Vault $vault;
    public function __construct(string $salt)
    {
        $this->key = hash('sha256', $salt . '|whmcstofossbilling-backup-v1', true);
        $this->vault = new Vault($salt);
    }
    public static function root(): string { return dirname(__DIR__, 2) . '/.local/backups'; }
    public static function directory(string $id): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new SafeException('Invalid backup identifier.');
        return self::root() . '/' . $id;
    }
    public function key(): string { return $this->key; }
    public function read(string $id, ?int $owner = null): array
    {
        $file = self::directory($id) . '/state';
        if (!is_file($file)) throw new SafeException('Private backup was not found. Upload it again or select a saved source.');
        $state = $this->vault->open(file_get_contents($file));
        if ($owner !== null && $state['owner'] !== $owner) throw new SafeException('This backup belongs to another administrator.');
        return $state;
    }
    private function save(string $id, array $state): void
    {
        $path = self::directory($id) . '/state';
        if (strlen(json_encode($state, JSON_THROW_ON_ERROR)) > 16777216) throw new SafeException('Backup indexing metadata exceeds the supported limit.');
        if (file_put_contents($path . '.next', $this->vault->seal($state), LOCK_EX) === false || !rename($path . '.next', $path)) throw new SafeException('Private backup checkpoint could not be saved.');
        @chmod($path, 0600);
    }
    public function begin(array $input, int $owner): array
    {
        if (PHP_INT_SIZE < 8) throw new SafeException('Backup uploads require 64-bit PHP for large-file offsets.');
        $name = strtolower((string) ($input['filename'] ?? ''));
        $format = str_ends_with($name, '.sql') ? 'sql' : (str_ends_with($name, '.zip') ? 'zip' : (str_ends_with($name, '.gz') ? 'gz' : ''));
        $size = filter_var($input['size'] ?? 0, FILTER_VALIDATE_INT);
        if (!$format || !$size || $size < 1 || $size > self::MAX_UPLOAD) throw new SafeException('Choose a SQL, SQL.GZ, GZ or ZIP backup up to 64 GiB.');
        if ($format !== 'sql' && !extension_loaded('zlib')) throw new SafeException('PHP zlib is required for compressed backups. Upload plain SQL or enable zlib on this server.');
        $prefix = (string) ($input['prefix'] ?? 'tbl');
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,31}\z/D', $prefix)) throw new SafeException('Invalid WHMCS table prefix.');
        $timezone = (string) ($input['source_timezone'] ?? '');
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) throw new SafeException('Select the WHMCS application timezone.');
        if (!is_dir(self::root()) && !mkdir(self::root(), 0700, true)) throw new SafeException('Private backup storage is not writable.');
        $free = disk_free_space(self::root());
        if ($free !== false && $free < $size + 67108864) throw new SafeException('Not enough free space to receive this backup.');
        $id = bin2hex(random_bytes(16)); mkdir(self::directory($id), 0700);
        $this->save($id, ['owner' => $owner, 'phase' => 'upload', 'format' => $format, 'size' => $size, 'uploaded' => 0, 'expanded' => 0, 'offset' => 0, 'prefix' => $prefix, 'source_timezone' => $timezone, 'confirmed_whmcs8' => ($input['confirm_whmcs8'] ?? '') === 'WHMCS 8.x', 'version' => null, 'tables' => [], 'insert' => null, 'zip' => null, 'sql_file' => $format === 'sql' ? 'upload' : 'expanded']);
        return ['backup_id' => $id, 'chunk_size' => EncryptedFile::BLOCK];
    }
    public function operate(string $id, int $owner, string $operation, array $input = []): array
    {
        $directory = self::directory($id);
        $state = $this->read($id, $owner);
        $lock = fopen($directory . '/lock', 'c+b');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new SafeException('This backup is busy. Wait for the current request.');
        try {
            $state = $this->read($id, $owner);
            if ($state['phase'] === 'failed') throw new SafeException('Backup preparation stopped. Remove this upload and start again with a supported complete export.');
            if ($operation === 'chunk') {
                if ($state['phase'] !== 'upload') throw new SafeException('This backup is no longer accepting data.');
                $data = base64_decode((string) ($input['data'] ?? ''), true);
                $offset = filter_var($input['offset'] ?? -1, FILTER_VALIDATE_INT);
                if ($data === false || !$data || strlen($data) > EncryptedFile::BLOCK || $offset === false || $offset < 0 || $offset + strlen($data) > $state['size']) throw new SafeException('Invalid backup upload block.');
                $path = $directory . '/upload';
                $actual = EncryptedFile::size($path);
                if ($offset < $actual) {
                    $stream = EncryptedFile::open($path, $this->key, $offset, min(strlen($data), $actual - $offset));
                    try { $same = stream_get_contents($stream); } finally { fclose($stream); }
                    if (!hash_equals($data, $same)) throw new SafeException('Upload retry does not match the saved block.');
                } elseif ($offset === $actual) EncryptedFile::append($path, $this->key, $data);
                else throw new SafeException('Upload block is out of order.');
                $state['uploaded'] = EncryptedFile::size($path);
            } elseif ($operation === 'prepare') {
                if ($state['phase'] === 'upload') {
                    if ($state['uploaded'] !== $state['size']) throw new SafeException('The backup upload is incomplete.');
                    if ($state['format'] === 'zip') $state['zip'] = Compression::zip($directory . '/upload', $this->key);
                    $state['phase'] = $state['format'] === 'sql' ? 'parse' : 'expand';
                }
                if ($state['phase'] === 'expand') $this->expand($directory, $state);
                elseif ($state['phase'] === 'verify') {
                    if (EncryptedFile::size($directory . '/expanded') !== $state['expanded']) throw new SafeException('Expanded backup checkpoint is incomplete.');
                    $crc = sprintf('%08x', $state['crc'] ?? 0);
                    if ($state['format'] === 'zip') $expected = sprintf('%08x', $state['zip']['crc']);
                    else {
                        $tail = EncryptedFile::open($directory . '/upload', $this->key, $state['size'] - 8, 8);
                        try { $expected = sprintf('%08x', unpack('Vcrc', fread($tail, 8))['crc']); } finally { fclose($tail); }
                    }
                    if (!is_string($crc) || !hash_equals($expected, $crc)) throw new SafeException('Compressed backup integrity check failed. No records were imported.');
                    $state['phase'] = 'parse';
                } elseif ($state['phase'] === 'parse') {
                    foreach (glob($directory . '/*.rows') ?: [] as $path) {
                        $name = basename($path, '.rows');
                        if (!isset($state['tables'][$name])) unlink($path);
                        else EncryptedFile::truncate($path, $this->key, $state['tables'][$name]['bytes'] ?? 0);
                    }
                    (new Parser($directory, $this->key))->step($state);
                }
            } else throw new SafeException('Unknown backup operation.');
            $this->save($id, $state);
            return $this->progress($id, $state);
        } catch (\Throwable $error) {
            if ($operation === 'prepare') { $state['phase'] = 'failed'; $this->save($id, $state); }
            throw $error;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private function expand(string $directory, array &$state): void
    {
        EncryptedFile::truncate($directory . '/expanded', $this->key, $state['expanded']);
        $started = microtime(true);
        // Replaying compression to a checkpoint avoids serialized native decompressor
        // state. Plain SQL skips this phase entirely.
        $skip = $state['expanded']; $written = 0; $buffer = '';
        foreach (Compression::chunks($directory . '/upload', $this->key, $state['format'], $state['zip']) as $part) {
                if ($skip >= strlen($part)) { $skip -= strlen($part); continue; }
                if ($skip) { $part = substr($part, $skip); $skip = 0; }
                $buffer .= $part;
                if (strlen($buffer) < 1048576) continue;
                $this->expandedBlock($directory, $state, $buffer); $written += strlen($buffer); $buffer = '';
                if ($written >= 67108864 || microtime(true) - $started >= 3) return;
        }
        if ($skip) throw new SafeException('Compressed checkpoint exceeds the expanded stream.');
        if ($buffer !== '') $this->expandedBlock($directory, $state, $buffer);
        $state['phase'] = 'verify';
        if ($state['format'] === 'zip' && $state['expanded'] !== $state['zip']['size']) throw new SafeException('ZIP expanded size does not match its directory.');
    }
    private function expandedBlock(string $directory, array &$state, string $part): void
    {
        if ($state['expanded'] + strlen($part) > self::MAX_EXPANDED) throw new SafeException('Expanded backup exceeds the 128 GiB storage limit.');
        EncryptedFile::append($directory . '/expanded', $this->key, $part);
        $state['crc'] = Compression::combineCrc($state['crc'] ?? 0, crc32($part), strlen($part));
        $state['expanded'] += strlen($part);
    }
    public function progress(string $id, array $state): array
    {
        return ['backup_id' => $id, 'phase' => $state['phase'], 'uploaded' => $state['uploaded'], 'size' => $state['size'], 'expanded' => $state['expanded'], 'parsed' => $state['offset'], 'sql_size' => EncryptedFile::size(self::directory($id) . '/' . $state['sql_file']), 'tables' => count($state['tables'])];
    }
    public function remove(string $id, int $owner): void
    {
        $this->read($id, $owner);
        $lock = fopen(self::directory($id) . '/lock', 'c+b');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new SafeException('Backup is busy.');
        try {
            foreach (glob(self::directory($id) . '/*') ?: [] as $path) if (is_file($path) && basename($path) !== 'lock') unlink($path);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        unlink(self::directory($id) . '/lock'); rmdir(self::directory($id));
    }
}
