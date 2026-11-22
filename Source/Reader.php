<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source;

use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Security\Sensitive;

/** No caller-supplied SQL, source writes, locking reads, routines or session SET commands. */
final class Reader
{
    private \PDO $pdo;
    private bool $mysql;
    private bool $repeatableReads;
    private readonly array $options;
    private array $connection;

    public function __construct(#[\SensitiveParameter] array $options)
    {
        $this->options = $options = ConnectionOptions::validate($options, true);
        $this->pdo = ConnectionFactory::connect($options);
        $this->connection = ConnectionFactory::status($this->pdo, $options['security_mode']) + ['database' => $options['database']];
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        $this->mysql = stripos($version, 'MariaDB') === false;
        $this->connection['privileges'] = GrantPolicy::inspect($this->pdo, $options['database'], $version);
        try {
            $isolation = $this->pdo->query('SELECT @@SESSION.transaction_isolation')->fetchColumn();
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1193) throw $error;
            $isolation = $this->pdo->query('SELECT @@SESSION.tx_isolation')->fetchColumn();
        }
        $isolation = str_replace([' ', '_'], '-', strtoupper((string) $isolation));
        if (!in_array($isolation, ['REPEATABLE-READ', 'READ-COMMITTED'], true)) {
            throw new SafeException('Source isolation must support non-locking consistent reads. No session/server isolation setting was changed.');
        }
        $this->repeatableReads = $isolation === 'REPEATABLE-READ';
        // Explicitly requested transaction-local read-only protection; no persistent server/session changes.
        $this->pdo->exec('START TRANSACTION READ ONLY');
        // Session defaults do not reflect START TRANSACTION READ ONLY. Inspect the current transaction instead.
        // On MySQL, performance_schema visibility is not guaranteed to SELECT-only users.
        // Successful server acceptance of READ ONLY plus verified grants is the enforced protection.
    }

    public function connectionStatus(): array
    {
        return $this->connection;
    }

    public function __destruct()
    {
        try {
            if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        } catch (\Throwable) {
            // Disconnect already releases a read-only transaction. Do not turn a
            // successful destination checkpoint into an unsanitized shutdown error.
        }
    }

    public static function identifier(string $name): string
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $name)) {
            throw new SafeException('An unrecognized SQL identifier was rejected.');
        }

        return '`' . $name . '`';
    }

    public function inspect(): array
    {
        $statement = $this->pdo->prepare("SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.DATA_TYPE, c.IS_NULLABLE, c.COLUMN_KEY,t.ENGINE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA = ? AND t.TABLE_TYPE='BASE TABLE' ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION");
        $statement->execute([$this->options['database']]);
        return self::fromColumns($statement, $this->options['prefix']);
    }

    public static function fromColumns(iterable $columns, string $prefix): array
    {
        $tables = [];
        foreach ($columns as $column) {
            $name = $column['TABLE_NAME'];
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            self::identifier($name);
            self::identifier($column['COLUMN_NAME']);
            if (in_array($column['COLUMN_NAME'], ['__wm_oversized', '__wm_missing'], true)) throw new SafeException('Source schema collides with reserved extractor metadata names.');
            $suffix = substr($name, strlen($prefix));
            $tables[$name] ??= ['suffix' => $suffix, 'engine' => $column['ENGINE'], 'columns' => [], 'key' => null, 'classification' => Sensitive::table($suffix) ? 'excluded_sensitive' : 'archive'];
            $tables[$name]['columns'][$column['COLUMN_NAME']] = [
                'type' => $column['COLUMN_TYPE'],
                'sensitive' => Sensitive::field($column['COLUMN_NAME']),
            ];
            if ($column['COLUMN_KEY'] === 'PRI') {
                $tables[$name]['primary'][] = $column['COLUMN_NAME'];
                if (in_array($column['DATA_TYPE'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) {
                    $tables[$name]['key'] = $column['COLUMN_NAME'];
                }
            }
        }
        foreach ($tables as &$table) {
            if (in_array($table['suffix'], ['customfieldsvalues', 'addonmodules', 'domainlog', 'ticketmaillog', 'emailtemplates'], true)) {
                // Opaque values may contain module credentials or password custom fields.
                $table['classification'] = 'requires_review';
            }
            if (count($table['primary'] ?? []) !== 1 || $table['key'] === null) {
                $table['classification'] = $table['classification'] === 'excluded_sensitive' ? 'excluded_sensitive' : 'unsupported_key';
            }
            // Generic custom-module tables can hide secrets in value/config columns.
            if (!Catalog::known($table['suffix']) && $table['classification'] === 'archive') {
                $table['classification'] = 'requires_review';
            }
        }
        unset($table);
        foreach (['clients', 'products', 'hosting', 'invoices', 'invoiceitems', 'accounts'] as $required) {
            if (!isset($tables[$prefix . $required])) {
                throw new SafeException('Required WHMCS tables are missing or inaccessible. This is not a supported complete source schema.');
            }
        }

        return $tables;
    }

    public function version(array $schema): ?string
    {
        // Only the version setting is read; never read the configuration table wholesale.
        $table = $this->options['prefix'] . 'configuration';
        if (!isset($schema[$table]['columns']['setting'], $schema[$table]['columns']['value'])) {
            return null;
        }
        $s = $this->pdo->prepare('SELECT ' . $this->hint() . ' `value` FROM ' . self::identifier($table) . ' WHERE `setting` = ? LIMIT 1');
        $s->execute(['Version']);
        $version = $s->fetchColumn();

        return is_string($version) && preg_match('/\A\d+\.\d+(?:\.\d+)?(?:[-+.\w]*)\z/D', $version) ? $version : null;
    }

    public function bounds(string $name, array $definition): array
    {
        $this->assertReadable($name, $definition);
        $key = self::identifier($definition['key']);
        $row = $this->pdo->query('SELECT ' . $this->hint() . ' COUNT(*) AS n, MIN(' . $key . ') AS minimum, MAX(' . $key . ') AS maximum FROM ' . self::identifier($name))->fetch();
        if ($row['minimum'] !== null && bccomp((string) $row['minimum'], '0', 0) <= 0) {
            throw new SafeException('A source key is zero or negative. A reviewed cursor strategy is required; no rows were silently skipped.');
        }

        return ['count' => (int) $row['n'], 'maximum' => (string) ($row['maximum'] ?? '0')];
    }

    public function batch(string $name, array $definition, string $after, string $maximum, int $limit): array
    {
        return $this->page($name, $definition, $after, $maximum, $limit)['rows'];
    }

    public function page(string $name, array $definition, string $after, string $maximum, int $limit): array
    {
        $this->assertReadable($name, $definition);
        if (!ctype_digit($after) || !ctype_digit($maximum) || $limit < 1 || $limit > 100) {
            throw new SafeException('Invalid source batch cursor.');
        }
        $columns = array_keys(array_filter($definition['columns'], static fn (array $column): bool => !$column['sensitive']));
        if (!in_array($definition['key'], $columns, true)) {
            throw new SafeException('The source key is sensitive and cannot be imported.');
        }
        $key = self::identifier($definition['key']);
        // A non-snapshot engine or READ COMMITTED may change between the length
        // probe and value SELECT. Restrict to one bounded row in that case.
        if (!$this->repeatableReads || ($definition['engine'] ?? '') !== 'InnoDB') $limit = 1;
        $quoted = array_map(self::identifier(...), $columns);
        $sizes = array_map(static fn (string $column): string => 'COALESCE(OCTET_LENGTH(' . $column . '),0)', $quoted);
        $sum = '(' . implode('+', $sizes) . ')';
        $max = 'GREATEST(' . implode(',', [...$sizes, '0']) . ')';
        $sizesStatement = $this->pdo->prepare('SELECT ' . $this->hint() . "$key AS row_key,$sum AS row_bytes,$max AS field_bytes FROM " . self::identifier($name) . " WHERE $key > ? AND $key <= ? ORDER BY $key LIMIT " . $limit);
        $sizesStatement->execute([$after, $maximum]);
        $candidates = $sizesStatement->fetchAll();
        if (!$candidates) return ['rows' => [], 'exhausted' => true];
        $budget = self::byteBudget();
        $selected = [];
        $bytes = 0;
        foreach ($candidates as $candidate) {
            $cost = min(4194304, (int) $candidate['row_bytes']) + count($columns) * 128;
            if ($selected && $bytes + $cost > $budget) break;
            $selected[] = $candidate;
            $bytes += $cost;
            if ($bytes >= $budget) break;
        }
        $rowMaximum = min(4194304, $budget);
        $oversized = "($sum > $rowMaximum OR $max > 2097152)";
        $projection = array_map(static function (string $column) use ($definition, $oversized): string {
            $identifier = self::identifier($column);
            if ($column === $definition['key']) return $identifier;
            // Evaluate limits on the server: oversized bodies never enter PHP.
            $expression = preg_match('/\A(?:decimal|numeric|float|double|real)\b/i', $definition['columns'][$column]['type']) ? 'CAST(' . $identifier . ' AS CHAR)' : $identifier;

            return "CASE WHEN $oversized THEN NULL ELSE $expression END AS $identifier";
        }, $columns);
        $last = (string) $selected[array_key_last($selected)]['row_key'];
        $statement = $this->pdo->prepare('SELECT ' . $this->hint() . implode(', ', $projection) . ",$oversized AS __wm_oversized FROM " . self::identifier($name) . " WHERE $key > ? AND $key <= ? ORDER BY $key LIMIT " . count($selected));
        $statement->execute([$after, $last]);
        $byKey = [];
        foreach ($statement as $row) $byKey[(string) $row[$definition['key']]] = $row;
        $rows = [];
        foreach ($selected as $candidate) {
            $id = (string) $candidate['row_key'];
            $rows[] = $byKey[$id] ?? [$definition['key'] => $id, '__wm_missing' => 1];
        }

        return ['rows' => $rows, 'exhausted' => count($candidates) < $limit && count($selected) === count($candidates)];
    }

    private static function byteBudget(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') return 8388608;
        $bytes = (int) $limit;
        $bytes *= match (strtolower(substr($limit, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };

        // Leave room for PDO buffers, arrays and worst-case JSON escaping.
        return max(65536, min(8388608, intdiv(max(0, $bytes - memory_get_usage(true)), 12)));
    }

    private function assertReadable(string $name, array $definition): void
    {
        if (!str_starts_with($name, $this->options['prefix']) || in_array($definition['classification'], ['excluded_sensitive', 'requires_review', 'unsupported_key'], true)) {
            throw new SafeException('This source table is excluded or requires a reviewed extractor.');
        }
    }

    private function hint(): string
    {
        return $this->mysql ? '/*+ MAX_EXECUTION_TIME(5000) */ ' : '';
    }
}
