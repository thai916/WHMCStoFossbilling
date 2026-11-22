<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source\Backup;

use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Security\Sensitive;
use Box\Mod\Whmcstofossbilling\Source\Reader;
use Box\Mod\Whmcstofossbilling\Source\Catalog;

final class Parser
{
    private array $buffers = [];
    private array $lengths = [];
    private array $indexBuffers = [];
    private array $indexLengths = [];
    private int $bufferBytes = 0;
    private int $indexBufferBytes = 0;
    public function __construct(private string $directory, private string $key) {}

    /** One bounded step; cursor is always between SQL statements or complete INSERT tuples. */
    public function step(array &$state): void
    {
        $stream = EncryptedFile::open($this->directory . '/' . $state['sql_file'], $this->key);
        $lexer = new Lexer($stream, $state['offset'], (string) ($state['probe'] ?? 'none'));
        $started = microtime(true);
        try {
            do {
                if ($state['insert'] !== null) {
                    $this->tuple($lexer, $state);
                } else {
                    $token = $lexer->next();
                    if ($token[0] === 'end') { $this->finish($state); break; }
                    $word = strtoupper($token[1]);
                    switch ($word) {
                        case ';': break;
                        case 'CREATE':
                            if (strtoupper($lexer->peek()[1]) === 'DATABASE') { $this->statement($lexer); break; }
                            $lexer->expect('TABLE');
                            if (strtoupper($lexer->peek()[1]) === 'IF') { $lexer->expect('IF'); $lexer->expect('NOT'); $lexer->expect('EXISTS'); }
                            $this->definition($lexer, $state);
                            break;
                        case 'INSERT':
                            $lexer->expect('INTO');
                            $name = $this->name($lexer);
                            if (!isset($state['tables'][$name])) throw new SafeException('INSERT data has no preceding table definition: ' . $name . '. Include table structure in the export.');
                            $columns = array_keys($state['tables'][$name]['columns']);
                            if ($lexer->peek()[1] === '(') {
                                $lexer->next(); $columns = [];
                                do { $columns[] = $this->name($lexer); $separator = $lexer->next()[1]; } while ($separator === ',');
                                if ($separator !== ')') throw new SafeException('Invalid INSERT column list.');
                            }
                            if (count(array_unique($columns)) !== count($columns) || array_diff($columns, array_keys($state['tables'][$name]['columns']))) throw new SafeException('INSERT columns do not match their table definition.');
                            // Partial rows could fabricate absent history/defaults. Require a complete export.
                            if (count($columns) !== count($state['tables'][$name]['columns'])) throw new SafeException('Partial INSERT column lists need a reviewed extractor. Export all columns.');
                            $lexer->expect('VALUES');
                            $state['insert'] = ['table' => $name, 'columns' => $columns];
                            break;
                        case 'ALTER':
                            $lexer->expect('TABLE'); $name = $this->name($lexer);
                            $tokens = $this->statement($lexer);
                            if (!isset($state['tables'][$name])) throw new SafeException('ALTER metadata refers to an unknown table.');
                            $text = implode(' ', array_column($tokens, 1));
                            if (preg_match('/\b(?:DROP|RENAME|CHANGE|CONVERT|DISCARD|IMPORT)\b/i', $text)) throw new SafeException('Unsupported schema-changing dump metadata. Export final table definitions and INSERT data.');
                            if (preg_match('/\bADD\s+(?!(?:PRIMARY\s+KEY|UNIQUE\s+(?:KEY|INDEX)|KEY|INDEX)\b)/i', $text)) throw new SafeException('Late column/schema additions are unsupported. Export final CREATE TABLE definitions.');
                            if (preg_match('/\bMODIFY\s+(?:COLUMN\s+)?([A-Za-z_][A-Za-z0-9_]*)\s+([A-Za-z]+)/i', $text, $m)) {
                                if ($m[1] !== $state['tables'][$name]['key'] || strtolower($m[2]) !== ($state['tables'][$name]['columns'][$m[1]]['data_type'] ?? '') || !preg_match('/\bAUTO_INCREMENT\b/i', $text)) throw new SafeException('Late type changes are unsupported. Export final CREATE TABLE definitions.');
                            }
                            if (preg_match('/\bADD\s+PRIMARY\s+KEY\s*\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)/i', $text, $m)) {
                                if ($state['tables'][$name]['key'] !== $m[1]) throw new SafeException('A late primary key differs from the indexed export key. Export primary keys in CREATE TABLE.');
                                $state['tables'][$name]['primary'] = [$m[1]];
                            } elseif (preg_match('/\bPRIMARY\b/i', $text)) throw new SafeException('Unsupported late primary key metadata.');
                            break;
                        case 'SET':
                            $text = implode(' ', array_column($this->statement($lexer), 1));
                            Lexer::assertMetadata($text);
                            break;
                        case 'USE': case 'LOCK': case 'UNLOCK': case 'START': case 'COMMIT': case 'DROP':
                            // Backup scaffolding is consumed as text, NEVER executed.
                            $this->statement($lexer);
                            break;
                        default:
                            throw new SafeException('Unsupported SQL statement type at byte ' . $token[2] . '. Export table structure and INSERT data only. No uploaded SQL was executed.');
                    }
                }
                $state['offset'] = $lexer->offset();
            } while (microtime(true) - $started < 3 && $state['phase'] === 'parse');
            foreach (array_unique(array_merge(array_keys($this->buffers), array_keys($this->indexBuffers))) as $name) $this->flush($name);
            foreach ($state['tables'] as $name => &$table) {
                $table['bytes'] = EncryptedFile::size($this->directory . '/' . $name . '.rows');
                $table['index_bytes'] = EncryptedFile::size($this->directory . '/' . $name . '.index');
            }
            unset($table);
            $state['offset'] = $lexer->offset();
            $state['probe'] = $lexer->probeState();
        } finally { fclose($stream); }
    }

    private function name(Lexer $lexer): string
    {
        $token = $lexer->next();
        if ($token[0] !== 'word') throw new SafeException('A SQL identifier is invalid.');
        Reader::identifier($token[1]);
        return $token[1];
    }

    private function statement(Lexer $lexer): array
    {
        $tokens = [];
        while (true) {
            $token = $lexer->next();
            if ($token[0] === 'end') throw new SafeException('A SQL statement is incomplete.');
            if ($token[1] === ';' && $token[0] === 'symbol') return $tokens;
            $tokens[] = $token;
            if (count($tokens) > 32768) throw new SafeException('SQL metadata exceeds the supported limit.');
        }
    }

    private function definition(Lexer $lexer, array &$state): void
    {
        $name = $this->name($lexer);
        if (isset($state['tables'][$name])) throw new SafeException('Duplicate table definition in backup: ' . $name . '.');
        if (count($state['tables']) >= 1000) throw new SafeException('Backup table limit exceeded.');
        $lexer->expect('('); $parts = []; $part = []; $depth = 0;
        while (true) {
            $token = $lexer->next();
            if ($token[0] === 'end') throw new SafeException('CREATE TABLE is incomplete.');
            if ($token[0] === 'symbol' && $token[1] === ')' && $depth === 0) { $parts[] = $part; break; }
            if ($token[0] === 'symbol' && $token[1] === ',' && $depth === 0) { $parts[] = $part; $part = []; continue; }
            if ($token[0] === 'symbol' && $token[1] === '(') ++$depth;
            if ($token[0] === 'symbol' && $token[1] === ')') --$depth;
            $part[] = $token;
            if (count($part) > 4096 || count($parts) > 1000) throw new SafeException('Table definition exceeds the supported limit.');
        }
        $this->statement($lexer);
        $table = ['columns' => [], 'primary' => [], 'key' => null, 'count' => 0, 'maximum' => '0', 'bytes' => 0, 'index_bytes' => 0];
        foreach ($parts as $part) {
            // Defaults and ENUM strings are values, never metadata keywords.
            $text = implode(' ', array_column(array_values(array_filter($part, static fn (array $token): bool => $token[0] !== 'string')), 1));
            if (preg_match('/\APRIMARY\s+KEY\s*\(([^)]+)\)/i', $text, $m)) {
                $table['primary'] = array_map('trim', explode(',', $m[1])); continue;
            }
            if (preg_match('/\A(?:KEY|UNIQUE|CONSTRAINT|FULLTEXT|SPATIAL|CHECK)\b/i', $text)) continue;
            $column = $part[0][1] ?? ''; Reader::identifier($column);
            if (isset($table['columns'][$column])) throw new SafeException('Duplicate column in SQL table definition.');
            $type = strtolower($part[1][1] ?? '');
            if (!preg_match('/\A[a-z]+\z/D', $type)) throw new SafeException('Unsupported source column type.');
            $typeText = $type;
            if (($part[2][1] ?? '') === '(') {
                $typeText .= '(';
                for ($i = 3; isset($part[$i]); ++$i) { $typeText .= $part[$i][1]; if ($part[$i][1] === ')') break; }
            }
            if (preg_match('/\bunsigned\b/i', $text)) $typeText .= ' unsigned';
            $table['columns'][$column] = ['type' => $typeText, 'data_type' => $type];
            if (preg_match('/\bPRIMARY\s+KEY\b/i', $text)) $table['primary'][] = $column;
        }
        if (count($table['primary']) === 1) $table['key'] = $table['primary'][0];
        elseif (!$table['primary'] && isset($table['columns']['id'])) $table['key'] = 'id'; // provisional until late ALTER metadata is verified
        $state['tables'][$name] = $table;
    }

    private function tuple(Lexer $lexer, array &$state): void
    {
        $insert = $state['insert']; $name = $insert['table']; $table = &$state['tables'][$name];
        $lexer->expect('('); $row = []; $bytes = 0;
        foreach ($insert['columns'] as $index => $column) {
            if ($index) $lexer->expect(',');
            $token = $lexer->next();
            if ($token[0] === 'string') $value = $token[1];
            elseif ($token[0] === 'word' && strtoupper($token[1]) === 'NULL') $value = null;
            elseif ($token[0] === 'word' && preg_match('/\A-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\z/D', $token[1])) $value = $token[1];
            elseif ($token[0] === 'word' && preg_match('/\A0x([a-f0-9]*)\z/iD', $token[1], $m) && strlen($m[1]) % 2 === 0) $value = hex2bin($m[1]);
            else throw new SafeException('Unsupported SQL value expression in ' . $name . ' near byte ' . $token[2] . '. Values were not executed or guessed.');
            $bytes += strlen((string) $value);
            if ($bytes > 8388608) throw new SafeException('A SQL row exceeds the supported extraction limit; no truncated history was imported.');
            if (!Sensitive::field($column) && !Sensitive::table(substr($name, strlen($state['prefix'])))) $row[$column] = $value;
            // Configuration secrets are discarded; only a syntactically valid Version is inspected.
            if ($name === $state['prefix'] . 'configuration' && $column === 'setting') $setting = $value;
            if ($name === $state['prefix'] . 'configuration' && $column === 'value') $configurationValue = $value;
        }
        $lexer->expect(')');
        $separator = $lexer->next();
        if ($separator[0] !== 'symbol' || !in_array($separator[1], [',', ';'], true)) throw new SafeException('INSERT suffixes and expressions are unsupported. Export plain INSERT VALUES data.');
        if ($separator[1] === ';') $state['insert'] = null;
        if (($setting ?? '') === 'Version' && is_string($configurationValue ?? null) && preg_match('/\A\d+\.\d+(?:\.\d+)?(?:[-+.\w]*)\z/D', $configurationValue)) $state['version'] = $configurationValue;
        $suffix = substr($name, strlen($state['prefix']));
        if (!str_starts_with($name, $state['prefix']) || Sensitive::table($suffix) || !Catalog::known($suffix) || in_array($suffix, ['customfieldsvalues', 'addonmodules', 'domainlog', 'ticketmaillog', 'emailtemplates'], true) || !$table['key'] || !in_array($table['columns'][$table['key']]['data_type'] ?? '', ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) return;
        $key = (string) ($row[$table['key']] ?? '');
        if (!ctype_digit($key) || strlen($key) > 30 || bccomp($key, '0', 0) <= 0) throw new SafeException('Source keys in ' . $name . ' must be positive integers. Zero, negative and non-integer keys cannot identify preserved records.');
        $key = ltrim($key, '0');
        if ($key === '') $key = '0';
        if (bccomp($key, $table['maximum'], 0) > 0) $table['maximum'] = $key;
        try { $data = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n"; }
        catch (\JsonException) { throw new SafeException('Backup contains binary or non-UTF-8 values in ' . $name . '. Export UTF-8 data or use a reviewed binary extractor. No invalid bytes were replaced.'); }
        if (strlen($data) > 4194304 || max(array_map(static fn ($value): int => strlen((string) $value), $row)) > 2097152) $data = json_encode([$table['key'] => $key, '__wm_oversized' => 1], JSON_THROW_ON_ERROR) . "\n";
        $path = $this->directory . '/' . $name . '.rows';
        $this->lengths[$name] ??= EncryptedFile::size($path);
        $offset = $this->lengths[$name] + strlen($this->buffers[$name] ?? '');
        $record = KeyIndex::encode($key, $offset);
        $this->indexLengths[$name] ??= EncryptedFile::size($this->directory . '/' . $name . '.index');
        $this->buffers[$name] = ($this->buffers[$name] ?? '') . $data;
        $this->bufferBytes += strlen($data);
        $this->indexBuffers[$name] = ($this->indexBuffers[$name] ?? '') . $record;
        $this->indexBufferBytes += strlen($record);
        if (strlen($this->buffers[$name]) >= 1048576 || strlen($this->indexBuffers[$name]) >= 1048576) $this->flush($name);
        if ($this->bufferBytes >= 4194304 || $this->indexBufferBytes >= 4194304) foreach (array_unique(array_merge(array_keys($this->buffers), array_keys($this->indexBuffers))) as $pending) $this->flush($pending);
        ++$table['count'];
    }

    private function flush(string $name): void
    {
        if (($this->buffers[$name] ?? '') !== '') {
            EncryptedFile::append($this->directory . '/' . $name . '.rows', $this->key, $this->buffers[$name]);
            $this->lengths[$name] += strlen($this->buffers[$name]);
            $this->bufferBytes -= strlen($this->buffers[$name]);
        }
        unset($this->buffers[$name]);
        if (($this->indexBuffers[$name] ?? '') !== '') {
            EncryptedFile::append($this->directory . '/' . $name . '.index', $this->key, $this->indexBuffers[$name]);
            $this->indexLengths[$name] += strlen($this->indexBuffers[$name]);
            $this->indexBufferBytes -= strlen($this->indexBuffers[$name]);
        }
        unset($this->indexBuffers[$name]);
    }

    private function finish(array &$state): void
    {
        $columns = [];
        foreach ($state['tables'] as $name => $table) foreach ($table['columns'] as $column => $type) {
            $columns[] = ['TABLE_NAME' => $name, 'COLUMN_NAME' => $column, 'COLUMN_TYPE' => $type['type'], 'DATA_TYPE' => $type['data_type'], 'COLUMN_KEY' => in_array($column, $table['primary'], true) ? 'PRI' : '', 'ENGINE' => 'InnoDB'];
        }
        $state['schema'] = Reader::fromColumns($columns, $state['prefix']);
        if ($state['version'] !== null && !str_starts_with($state['version'], '8.')) throw new SafeException('The backup version is not WHMCS 8.x.');
        if ($state['version'] === null && !$state['confirmed_whmcs8']) throw new SafeException('Version was not detected. Verify WHMCS 8.x and upload with the manual version confirmation.');
        $state['phase'] = 'sort'; // Encrypted key/offset index is sorted in bounded steps before use.
    }
}
