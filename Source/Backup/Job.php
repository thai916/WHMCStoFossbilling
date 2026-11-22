<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source\Backup;

use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Security\Vault;

final class Job
{
    public const MAX_UPLOAD = 68719476736; // 64 GiB; bounded disk, not PHP upload_max_filesize.
    public const MAX_EXPANDED = 137438953472;
    private const SORT_RUN_RECORDS = 131072; // 6.4 MiB of fixed-width index data per sorted run.
    private const SORT_FAN_IN = 64;          // Bounds simultaneously open encrypted streams.
    private const SORT_SECONDS = 3.0;
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
        $this->save($id, ['owner' => $owner, 'phase' => 'upload', 'format' => $format, 'size' => $size, 'uploaded' => 0, 'expanded' => 0, 'offset' => 0, 'prefix' => $prefix, 'source_timezone' => $timezone, 'confirmed_whmcs8' => ($input['confirm_whmcs8'] ?? '') === 'WHMCS 8.x', 'version' => null, 'tables' => [], 'insert' => null, 'probe' => 'none', 'zip' => null, 'sql_file' => $format === 'sql' ? 'upload' : 'expanded']);
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
                    foreach (glob($directory . '/*') ?: [] as $path) {
                        if (!is_file($path)) continue;
                        $base = basename($path);
                        if (preg_match('/\.(?:run\d+|m\d+_\d+)\z/D', $base) || str_ends_with($base, '.sorted')) { unlink($path); continue; }
                        $extension = str_ends_with($base, '.rows') ? '.rows' : (str_ends_with($base, '.index') ? '.index' : '');
                        if ($extension === '') continue;
                        $name = substr($base, 0, -strlen($extension));
                        if (!isset($state['tables'][$name])) { unlink($path); continue; }
                        $length = $extension === '.rows' ? ($state['tables'][$name]['bytes'] ?? 0) : ($state['tables'][$name]['index_bytes'] ?? 0);
                        EncryptedFile::truncate($path, $this->key, $length);
                    }
                    (new Parser($directory, $this->key))->step($state);
                } elseif ($state['phase'] === 'sort') {
                    if (!isset($state['sort'])) $this->startSort($directory, $state);
                    if ($state['phase'] === 'sort') $this->sortStep($directory, $state);
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
    /** Prepare the bounded external sort of every table's (key, offset) index. */
    private function startSort(string $directory, array &$state): void
    {
        $tables = [];
        foreach ($state['tables'] as $name => $table) if (($table['count'] ?? 0) > 0) $tables[] = $name;
        if ($tables === []) { unset($state['sort']); $state['phase'] = 'ready'; return; }
        $state['sort'] = [
            'tables' => $tables, 'table_index' => 0, 'stage' => 'runs',
            'source_offset' => 0, 'run' => 0, 'inputs' => [], 'offsets' => [], 'heads' => [],
            'groups' => [], 'group' => 0, 'outputs' => [], 'pass' => 0,
            'output' => '', 'written' => 0, 'previous' => '', 'final' => false,
        ];
    }

    /** One bounded sort/merge unit. Checkpoints remain resumable after every request. */
    private function sortStep(string $directory, array &$state): void
    {
        $started = microtime(true);
        while (true) {
            $sort = &$state['sort'];
            $name = $sort['tables'][$sort['table_index']];
            if ($sort['stage'] === 'runs') {
                $done = $this->sortRunStep($directory, $state, $name);
                if (!$done || microtime(true) - $started >= self::SORT_SECONDS) return;
                $sort = &$state['sort'];
                $sort['inputs'] = [];
                for ($i = 0; $i < $sort['run']; ++$i) $sort['inputs'][] = $name . '.run' . $i;
                if ($sort['inputs'] === []) throw new SafeException('Prepared SQL key index is missing for ' . $name . '. Remove this backup and upload it again.');
                $sort['stage'] = 'merge';
                $this->prepareMergeGroups($state);
            } elseif ($sort['stage'] === 'prepare') {
                $this->prepareMergeGroups($state);
            } else {
                $done = $this->mergeStep($directory, $state, $started);
                if (!$done || microtime(true) - $started >= self::SORT_SECONDS) return;
                $sort = &$state['sort'];
                $sort['outputs'][] = $sort['output'];
                ++$sort['group'];
                if ($sort['group'] < count($sort['groups'])) { $this->beginMergeGroup($directory, $state, $name); continue; }
                foreach ($sort['inputs'] as $old) { $path = $directory . '/' . $old; if (is_file($path)) unlink($path); }
                $inputs = $sort['outputs'];
                $sort['inputs'] = $inputs;
                $sort['outputs'] = [];
                if ($sort['output'] === $name . '.sorted') {
                    $expected = ((int) ($state['tables'][$name]['count'] ?? 0)) * KeyIndex::RECORD;
                    if (EncryptedFile::size($directory . '/' . $name . '.sorted') !== $expected) throw new SafeException('Prepared SQL key index is incomplete for ' . $name . '. No records were imported.');
                    ++$sort['table_index'];
                    if ($sort['table_index'] >= count($sort['tables'])) { unset($state['sort']); $state['phase'] = 'ready'; return; }
                    $sort = &$state['sort'];
                    $sort['stage'] = 'runs'; $sort['source_offset'] = 0; $sort['run'] = 0;
                    $sort['inputs'] = []; $sort['offsets'] = []; $sort['heads'] = [];
                    $sort['groups'] = []; $sort['group'] = 0; $sort['outputs'] = []; $sort['pass'] = 0;
                    $sort['output'] = ''; $sort['written'] = 0; $sort['previous'] = ''; $sort['final'] = false;
                } else {
                    // Intermediate passes may collapse to one run; one extra bounded
                    // pass writes the final encrypted .sorted index.
                    EncryptedFile::truncate($directory . '/' . $name . '.sorted', $this->key, 0);
                    $this->prepareMergeGroups($state);
                }
            }
            if (microtime(true) - $started >= self::SORT_SECONDS) return;
        }
    }

    private function sortRunStep(string $directory, array &$state, string $name): bool
    {
        $path = $directory . '/' . $name . '.index';
        $size = EncryptedFile::size($path);
        if ($size % KeyIndex::RECORD !== 0) throw new SafeException('Prepared SQL key index has a partial record for ' . $name . '.');
        if (EncryptedFile::size($directory . '/' . $name . '.rows') !== ($state['tables'][$name]['bytes'] ?? 0)) throw new SafeException('Prepared SQL rows changed while indexing ' . $name . '. No records were imported.');
        $sort = &$state['sort'];
        if ($sort['source_offset'] >= $size) return true;
        $read = min(KeyIndex::RECORD * self::SORT_RUN_RECORDS, $size - $sort['source_offset']);
        $stream = EncryptedFile::open($path, $this->key, $sort['source_offset'], $read);
        try { $data = stream_get_contents($stream); } finally { fclose($stream); }
        if (!is_string($data) || strlen($data) !== $read) throw new SafeException('Prepared SQL key index could not be read for ' . $name . '.');
        $records = str_split($data, KeyIndex::RECORD);
        sort($records, SORT_STRING);
        $run = $directory . '/' . $name . '.run' . $sort['run'];
        EncryptedFile::truncate($run, $this->key, 0);
        EncryptedFile::append($run, $this->key, implode('', $records));
        $sort['source_offset'] += $read;
        ++$sort['run'];
        return $sort['source_offset'] >= $size;
    }

    private function prepareMergeGroups(array &$state): void
    {
        $sort = &$state['sort'];
        $sort['groups'] = array_chunk($sort['inputs'], self::SORT_FAN_IN);
        $sort['group'] = 0;
        $sort['outputs'] = [];
        ++$sort['pass'];
        $sort['final'] = count($sort['groups']) === 1 && count($sort['groups'][0] ?? []) === 1;
        $sort['stage'] = 'merge';
        $this->beginMergeGroupFromState($state);
    }

    private function beginMergeGroup(string $directory, array &$state, string $name): void
    {
        $this->beginMergeGroupFromState($state);
        $sort = &$state['sort'];
        EncryptedFile::truncate($directory . '/' . $sort['output'], $this->key, 0);
    }

    private function beginMergeGroupFromState(array &$state): void
    {
        $sort = &$state['sort'];
        $inputs = $sort['groups'][$sort['group']] ?? [];
        $sort['offsets'] = array_fill(0, count($inputs), 0);
        $sort['heads'] = array_fill(0, count($inputs), null);
        $sort['previous'] = '';
        $sort['written'] = 0;
        $name = $sort['tables'][$sort['table_index']];
        $sort['output'] = $sort['final'] ? $name . '.sorted' : $name . '.m' . $sort['pass'] . '_' . $sort['group'];
    }

    /** Bounded k-way merge; duplicate adjacent keys are rejected before a source becomes ready. */
    private function mergeStep(string $directory, array &$state, float $started): bool
    {
        $sort = &$state['sort'];
        $name = $sort['tables'][$sort['table_index']];
        $inputs = $sort['groups'][$sort['group']] ?? [];
        if ($inputs === []) throw new SafeException('Prepared SQL key index has an empty merge group for ' . $name . '.');
        $streams = []; $sizes = [];
        try {
            foreach ($inputs as $i => $file) {
                $path = $directory . '/' . $file;
                $sizes[$i] = EncryptedFile::size($path);
                if ($sizes[$i] % KeyIndex::RECORD !== 0) throw new SafeException('Prepared SQL key index run is incomplete for ' . $name . '.');
                if ($sort['offsets'][$i] > $sizes[$i]) throw new SafeException('Prepared SQL key index checkpoint is invalid for ' . $name . '.');
                $streams[$i] = EncryptedFile::open($path, $this->key, $sort['offsets'][$i], $sizes[$i] - $sort['offsets'][$i]);
            }
            $buffer = ''; $done = false;
            while (true) {
                if (microtime(true) - $started >= self::SORT_SECONDS) break;
                $min = -1;
                for ($i = 0; $i < count($inputs); ++$i) {
                    if ($sort['heads'][$i] === null && $sort['offsets'][$i] < $sizes[$i]) {
                        $record = fread($streams[$i], KeyIndex::RECORD);
                        if (!is_string($record) || strlen($record) !== KeyIndex::RECORD) throw new SafeException('Prepared SQL key index run could not be read for ' . $name . '.');
                        $sort['heads'][$i] = $record;
                        $sort['offsets'][$i] += KeyIndex::RECORD;
                    }
                    if ($sort['heads'][$i] !== null && ($min < 0 || KeyIndex::compare((string) $sort['heads'][$i], (string) $sort['heads'][$min]) < 0)) $min = $i;
                }
                if ($min < 0) { $done = true; break; }
                $record = (string) $sort['heads'][$min];
                $sort['heads'][$min] = null;
                if ($sort['previous'] !== '' && KeyIndex::compare($record, (string) $sort['previous']) === 0) throw new SafeException('Duplicate source keys in ' . $name . ' cannot silently lose history.');
                $sort['previous'] = $record;
                $buffer .= $record;
                $sort['written'] += KeyIndex::RECORD;
                if (strlen($buffer) >= 1048576) { EncryptedFile::append($directory . '/' . $sort['output'], $this->key, $buffer); $buffer = ''; }
            }
            if ($buffer !== '') EncryptedFile::append($directory . '/' . $sort['output'], $this->key, $buffer);
            return $done;
        } finally {
            foreach ($streams as $stream) fclose($stream);
        }
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
