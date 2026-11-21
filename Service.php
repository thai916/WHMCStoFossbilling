<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling;

use Box\Mod\Whmcstofossbilling\Destination\NativeWriter;
use Box\Mod\Whmcstofossbilling\Destination\Store;
use Box\Mod\Whmcstofossbilling\Installation\Schema;
use Box\Mod\Whmcstofossbilling\Mapping\Profile;
use Box\Mod\Whmcstofossbilling\Migration\Engine;
use Box\Mod\Whmcstofossbilling\Migration\Dependencies;
use Box\Mod\Whmcstofossbilling\Finance\Reconciler;
use Box\Mod\Whmcstofossbilling\Migration\Planner;
use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Security\Vault;
use Box\Mod\Whmcstofossbilling\Source\ConnectionOptions;
use Box\Mod\Whmcstofossbilling\Source\Reader;
use FOSSBilling\Config;

final class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        $result = [];
        foreach (['view' => 'View migration dashboard', 'configure' => 'Manage source credentials and mappings', 'execute' => 'Authorize and operate migration runs', 'archive' => 'Inspect private legacy records', 'export' => 'Export migration reports'] as $key => $name) {
            $result[$key] = ['type' => 'bool', 'display_name' => $name, 'description' => $name . '. Customer information is confidential.'];
        }

        return $result;
    }

    public function install(): bool
    {
        $this->requirements();
        Schema::install($this->store()->pdo);

        return true;
    }

    public function update(array $manifest = []): bool
    {
        return $this->install();
    }

    public function uninstall(): bool
    {
        // Deliberately retain all evidence and mappings. No cascade into imported business records.
        return true;
    }

    public function store(): Store
    {
        $connection = $this->di['dbal']->getNativeConnection();
        if (!$connection instanceof \PDO) {
            throw new SafeException('A PDO-backed destination connection is required.');
        }

        return new Store($connection);
    }

    private function vault(): Vault
    {
        return new Vault((string) Config::getProperty('info.salt'));
    }

    public function requirements(): array
    {
        if (version_compare(PHP_VERSION, '8.3', '<') || \FOSSBilling\Version::VERSION !== '0.8.9') {
            throw new SafeException('This adapter requires FOSSBilling 0.8.9 and PHP 8.3 or newer.');
        }
        foreach (['pdo_mysql', 'openssl', 'bcmath', 'mbstring', 'json'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new SafeException('A required PHP extension is missing: ' . $extension . '.');
            }
        }

        return ['php' => PHP_VERSION, 'fossbilling' => \FOSSBilling\Version::VERSION, 'blockers' => Planner::BLOCKERS];
    }

    public function sources(): array
    {
        return $this->store()->all('SELECT id,label,source_version,tested_at,created_at FROM whmcstofossbilling_source ORDER BY id DESC');
    }

    public function testConnection(#[\SensitiveParameter] array $input): array
    {
        $this->requirements();
        $options = ConnectionOptions::validate($input);
        $reader = new Reader($options);
        $schema = $reader->inspect();
        $version = $reader->version($schema);
        if ($version !== null && !str_starts_with($version, '8.')) {
            throw new SafeException('The detected source is not WHMCS 8.x.');
        }
        if ($version === null && ($input['confirm_whmcs8'] ?? '') !== 'WHMCS 8.x') {
            throw new SafeException('Source version could not be detected. Verify WHMCS 8.x manually and provide the explicit version confirmation.');
        }
        $options['timezone_detection'] = ($options['source_timezone'] ?? '') !== ''
            ? \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::selected($options['source_timezone'])
            : \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::unselected();
        $options['connection_status'] = $reader->connectionStatus() + [
            'table_availability' => ['available' => true, 'tables_found' => count($schema), 'required_tables' => ['clients', 'products', 'hosting', 'invoices', 'invoiceitems', 'accounts']],
        ];
        $store = $this->store();
        $store->verifyDestination();
        $fingerprint = hash('sha256', Store::json([$options['host'], $options['port'], $options['database'], $options['prefix']]));
        $lock = 'whmcstofossbilling-profile-' . substr($fingerprint, 0, 32);
        $store->lock($lock);
        try {
            $previous = $store->one('SELECT * FROM whmcstofossbilling_source WHERE fingerprint=?', [$fingerprint]);
            $mapping = $previous
                ? Store::json(Profile::rebase(json_decode($previous['mapping_json'], true, 128, JSON_THROW_ON_ERROR), $schema))
                : Store::json(Profile::defaults($schema));
            $label = $options['host'] . ':' . $options['port'] . '/' . $options['database'];
            $store->execute('INSERT INTO whmcstofossbilling_source (fingerprint,label,credentials,schema_json,mapping_json,source_version,tested_at,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE credentials=VALUES(credentials),schema_json=VALUES(schema_json),mapping_json=VALUES(mapping_json),source_version=VALUES(source_version),tested_at=UTC_TIMESTAMP()', [$fingerprint, $label, $this->vault()->seal($options), Store::json($schema), $mapping, $version]);
            $id = (int) $store->one('SELECT id FROM whmcstofossbilling_source WHERE fingerprint=?', [$fingerprint])['id'];

            return $this->mapping($id);
        } finally {
            $store->unlock($lock);
        }
    }

    public function mapping(int $source): array
    {
        $row = $this->store()->source($source);
        $schema = json_decode($row['schema_json'], true, 128, JSON_THROW_ON_ERROR);
        $profile = json_decode($row['mapping_json'], true, 128, JSON_THROW_ON_ERROR);
        $options = $this->vault()->open($row['credentials']);

        return [
            'source_id' => $source, 'label' => $row['label'], 'version' => $row['source_version'],
            'source_timezone' => $options['source_timezone'] ?? '',
            'timezone_detection' => $options['timezone_detection'] ?? null,
            'connection_status' => $options['connection_status'] ?? null,
            'tested_at' => $row['tested_at'],
            'schema' => $schema, 'profile' => $profile, 'fields' => Profile::describe($schema, $profile),
            'native_client_targets' => array_values(Profile::CLIENT_FIELDS),
            'native_entities' => NativeWriter::ENTITIES,
            'analysis' => $this->store()->all('SELECT table_name,expected,maximum,analyzed_at FROM whmcstofossbilling_analysis WHERE source_id=?', [$source]),
            'blockers' => Planner::BLOCKERS,
        ];
    }

    public function confirmSourceTimezone(int $source, string $timezone): array
    {
        $store = $this->store();
        $lock = 'whmcstofossbilling-source-' . $source;
        $store->lock($lock);
        try {
            $row = $store->source($source);
            $options = $this->vault()->open($row['credentials']);
            $options['timezone_detection'] = \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::selected($timezone);
            $options['source_timezone'] = $timezone;
            $store->execute('UPDATE whmcstofossbilling_source SET credentials=? WHERE id=?', [$this->vault()->seal($options), $source]);

            return $this->mapping($source);
        } finally {
            $store->unlock($lock);
        }
    }

    public function saveMapping(int $source, array $profile): array
    {
        $row = $this->store()->source($source);
        $schema = json_decode($row['schema_json'], true, 128, JSON_THROW_ON_ERROR);
        $clean = Profile::validate($profile, $schema);
        $this->store()->execute('UPDATE whmcstofossbilling_source SET mapping_json=? WHERE id=?', [Store::json($clean), $source]);

        return ['profile' => $clean, 'validated' => true];
    }

    public function analyze(int $source, string $name): array
    {
        $row = $this->store()->source($source);
        $schema = json_decode($row['schema_json'], true, 128, JSON_THROW_ON_ERROR);
        $definition = $schema[$name] ?? throw new SafeException('Unknown source table.');
        $reader = new Reader($this->vault()->open($row['credentials']));
        if (Store::json($reader->inspect()) !== $row['schema_json']) {
            throw new SafeException('The source schema changed. Test the connection again.');
        }
        $bounds = $reader->bounds($name, $definition);
        $this->store()->execute('INSERT INTO whmcstofossbilling_analysis (source_id,table_name,expected,maximum,schema_checksum,analyzed_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE expected=VALUES(expected),maximum=VALUES(maximum),schema_checksum=VALUES(schema_checksum),analyzed_at=UTC_TIMESTAMP()', [$source, $name, $bounds['count'], $bounds['maximum'], hash('sha256', $row['schema_json'])]);

        return ['table' => $name, ...$bounds];
    }

    public function createRun(array $input, int $admin): array
    {
        $this->requirements();
        $source = $this->store()->source((int) ($input['source_id'] ?? 0));
        $schema = json_decode($source['schema_json'], true, 128, JSON_THROW_ON_ERROR);
        $profile = Profile::validate(json_decode($source['mapping_json'], true, 128, JSON_THROW_ON_ERROR), $schema);
        $mode = (string) ($input['mode'] ?? 'dry_run');
        if (!in_array($mode, ['dry_run', 'archive', 'reference_native', 'historical_native'], true)) {
            throw new SafeException('Unknown migration mode.');
        }
        if ($mode !== 'dry_run' && (($input['confirmation'] ?? '') !== 'PRESERVE HISTORY' || empty($input['backup_verified']) || empty($input['accept_limitations']) || trim((string) ($input['backup_reference'] ?? '')) === '')) {
            throw new SafeException('Confirm a verified destination backup, acknowledge the limitations and type PRESERVE HISTORY.');
        }
        if ($mode === 'historical_native' && empty($input['destination_exclusive'])) throw new SafeException('Historical financial publication requires explicit confirmation of an otherwise idle, private destination. This tool does not disable global automation.');
        $invalidFallback = (string) ($input['invalid_date_fallback'] ?? '');
        if ($invalidFallback !== '' && $invalidFallback !== '1900') {
            throw new SafeException('Unknown invalid-date policy. Only the explicit 1900 fallback is available.');
        }
        $target = $mode === 'dry_run' ? (string) ($input['target_mode'] ?? 'reference_native') : $mode;
        if (!in_array($target, ['archive', 'reference_native', 'historical_native'], true)) throw new SafeException('Unknown dry-run target mode.');
        $selection = $input['tables'] ?? [];
        if (!is_array($selection) || count($selection) > 500) {
            throw new SafeException('Invalid source selection.');
        }
        if (in_array($target, ['reference_native', 'historical_native'], true)) {
            $selection = Dependencies::expand($schema, $selection);
        }
        if ($mode === 'historical_native' && Config::getProperty('i18n.timezone', 'UTC') !== 'UTC') {
            throw new SafeException('Historical financial adapters currently require a UTC-configured destination. No global timezone was changed; use archive mode pending a reviewed conversion adapter.');
        }
        if ($mode === 'reference_native' && Config::getProperty('i18n.timezone', 'UTC') !== 'UTC') {
            foreach ($selection as $name) {
                if (in_array($schema[$name]['suffix'], ['tickets', 'ticketreplies', 'ticketnotes', 'emails'], true)) throw new SafeException('Native history timestamp adapters currently require a UTC-configured destination. No global timezone was changed.');
                if ($schema[$name]['suffix'] === 'clients' && preg_match('/\\A(?:datetime|timestamp)\\b/i', $schema[$name]['columns']['datecreated']['type'] ?? '')) throw new SafeException('Native client registration timestamps require a UTC-configured destination. Date-only source registrations are unchanged.');
            }
        }
        $store = $this->store();
        $plan = Planner::build($store, (int) $source['id'], $schema, array_values(array_unique($selection)));
        \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::assertNativeStorage($schema, array_keys($plan['tables']), $target);
        $plan['destination_fingerprint'] = $store->verifyDestination()['fingerprint'];
        $options = $this->vault()->open($source['credentials']);
        $plan['source_timezone'] = $options['source_timezone'] ?? '';
        if ($mode !== 'dry_run' && $target !== 'archive' && $plan['source_timezone'] === '') {
            foreach (array_keys($plan['tables']) as $name) {
                $suffix = $schema[$name]['suffix'];
                $clientTimestamp = $suffix === 'clients' && preg_match('/\\A(?:datetime|timestamp)\\b/i', $schema[$name]['columns']['datecreated']['type'] ?? '');
                if ($clientTimestamp || in_array($suffix, ['tickets', 'ticketreplies', 'ticketnotes', 'emails'], true)
                    || ($target === 'historical_native' && in_array($suffix, ['accounts', 'credit', 'invoices', 'hosting'], true))) {
                    throw new SafeException('WHMCS application timezone is Unverified. Select and confirm the exact WHMCS application timezone before authorizing timezone-sensitive native records. Archive mode remains available.');
                }
            }
        }
        $plan['source_timezone_evidence'] = $options['timezone_detection'] ?? null;
        $plan['source_security_mode'] = ConnectionOptions::validate($options, true)['security_mode'];
        $plan['source_prefix'] = $options['prefix'];
        $plan['adapter_revision'] = 2;
        $plan['invalid_date_fallback'] = $invalidFallback === '1900' ? NativeWriter::DATE_FALLBACK : null;
        $plan['target_mode'] = $target;
        $plan['native_entities'] = $target === 'archive' ? [] : NativeWriter::ENTITIES;
        if ($target !== 'historical_native') unset($plan['native_entities']['invoices'], $plan['native_entities']['accounts'], $plan['native_entities']['hosting']);
        $plan['destination_timezone'] = Config::getProperty('i18n.timezone', 'UTC');
        $id = bin2hex(random_bytes(16));
        if ($store->pdo->inTransaction()) throw new SafeException('A destination transaction is already active. Migration plan creation cannot take ownership of another transaction.');
        $lock = 'whmcstofossbilling-source-' . $source['id'];
        $store->lock($lock);
        try {
            if ($mode !== 'dry_run' && $store->one("SELECT id FROM whmcstofossbilling_run WHERE source_id=? AND mode<>'dry_run' AND status IN ('running','paused') LIMIT 1", [$source['id']])) {
                throw new SafeException('Another archival/native run for this source is unfinished. Finish or cancel it before creating another non-preview run.');
            }
            $store->pdo->beginTransaction();
            $store->execute('INSERT INTO whmcstofossbilling_run (id,source_id,status,mode,schema_json,mapping_json,plan_json,owner_id,backup_reference,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$id, $source['id'], 'paused', $mode, Store::json($schema), Store::json($profile), Store::json($plan), $admin, mb_substr(trim((string) ($input['backup_reference'] ?? 'dry run')), 0, 255)]);
            foreach ($plan['tables'] as $table => $bounds) {
                $store->execute('INSERT INTO whmcstofossbilling_cursor (run_id,table_name,expected,maximum) VALUES (?,?,?,?)', [$id, $table, $bounds['count'], $bounds['maximum']]);
            }
            $store->pdo->commit();
        } catch (\Throwable $error) {
            if ($store->pdo->inTransaction()) $store->pdo->rollBack();
            throw $error;
        } finally {
            $store->unlock($lock);
        }

        return $this->status($id);
    }

    public function control(string $id, string $action): array
    {
        $store = $this->store();
        $run = $store->run($id);
        $lock = 'whmcstofossbilling-source-' . $run['source_id'];
        $store->lock($lock);
        try {
            $run = $store->run($id);
            $status = match ($action) {
                'pause' => $run['status'] === 'running' ? 'paused' : throw new SafeException('Only running migrations can be paused.'),
                'resume' => in_array($run['status'], ['paused', 'running'], true) ? 'running' : throw new SafeException('This migration cannot be resumed.'),
                'cancel' => in_array($run['status'], ['paused', 'running'], true) ? 'cancelled' : throw new SafeException('This migration is already stopped.'),
                default => throw new SafeException('Unknown migration action.'),
            };
            $store->execute('UPDATE whmcstofossbilling_run SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?', [$status, $id]);
        } finally {
            $store->unlock($lock);
        }

        return $this->status($id);
    }

    public function batch(string $run, string $request, int $limit): array
    {
        return (new Engine($this->store(), $this->vault()))->batch($run, $request, $limit);
    }

    public function retry(string $run): array
    {
        return (new Engine($this->store(), $this->vault()))->retry($run);
    }

    public function status(string $id): array
    {
        $run = $this->store()->run($id);
        unset($run['schema_json'], $run['mapping_json']);
        $run['plan'] = json_decode($run['plan_json'], true, 128, JSON_THROW_ON_ERROR);
        unset($run['plan_json']);
        $run['categories'] = $this->store()->all('SELECT table_name,expected,maximum,after_id,processed,imported,archived,skipped,failed,status FROM whmcstofossbilling_cursor WHERE run_id=? ORDER BY id', [$id]);
        $run['errors'] = $this->store()->all("SELECT table_name,source_key,error_type,message,retryable,attempts FROM whmcstofossbilling_attempt WHERE run_id=? AND status='failed' ORDER BY id LIMIT 100", [$id]);
        $run['date_fallbacks'] = $this->store()->all("SELECT table_name,source_key,message AS original_value FROM whmcstofossbilling_attempt WHERE run_id=? AND status='date_fallback' ORDER BY id LIMIT 100", [$id]);
        $run['reconciliation'] = (new Reconciler($this->store()))->summary($id);
        $run['product_pricing'] = $this->store()->all('SELECT currency,native_applied,COUNT(*) AS price_rows FROM whmcstofossbilling_price WHERE run_id=? GROUP BY currency,native_applied ORDER BY currency', [$id]);
        $run['credit_publication'] = $this->store()->all('SELECT source_key,status,message,attempts FROM whmcstofossbilling_credit_result WHERE run_id=? ORDER BY source_key LIMIT 100', [$id]);

        return $run;
    }

    public function runs(): array
    {
        return $this->store()->all('SELECT r.id,r.source_id,s.label,r.status,r.mode,r.created_at,r.updated_at,r.last_batch_at FROM whmcstofossbilling_run r JOIN whmcstofossbilling_source s ON s.id=r.source_id ORDER BY r.created_at DESC LIMIT 50');
    }

    public function archive(array $input): array
    {
        $source = (int) ($input['source_id'] ?? 0);
        $key = trim((string) ($input['source_key'] ?? ''));
        $table = trim((string) ($input['table_name'] ?? ''));
        $where = 'source_id=?';
        $values = [$source];
        if ($key !== '') {
            $where .= ' AND source_key=?';
            $values[] = $key;
        }
        if ($table !== '') {
            $where .= ' AND table_name=?';
            $values[] = $table;
        }
        if (trim((string) ($input['legacy_reference'] ?? '')) !== '') {
            $kind = (string) ($input['legacy_kind'] ?? 'invoice');
            if (!in_array($kind, ['invoice', 'ticket'], true)) throw new SafeException('Legacy reference kind must be invoice or ticket.');
            $where .= ' AND EXISTS (SELECT 1 FROM whmcstofossbilling_legacy_reference r WHERE r.source_id=whmcstofossbilling_archive.source_id AND r.table_name=whmcstofossbilling_archive.table_name AND r.source_key=whmcstofossbilling_archive.source_key AND r.kind=? AND r.reference_hash=?)';
            $values[] = $kind;
            $values[] = hash('sha256', trim((string) $input['legacy_reference']));
        }
        $offset = max(0, min(100000000, (int) ($input['offset'] ?? 0)));
        $rows = $this->store()->all('SELECT id,table_name,source_key,payload,original_dates,relationships,exclusions,run_id,created_at,updated_at FROM whmcstofossbilling_archive WHERE ' . $where . ' ORDER BY id LIMIT 25 OFFSET ' . $offset, $values);
        foreach ($rows as &$row) {
            foreach (['payload', 'original_dates', 'relationships', 'exclusions'] as $field) {
                $row[$field] = json_decode($row[$field], true, 128, JSON_THROW_ON_ERROR);
            }
            $row['destinations'] = $this->store()->all('SELECT destination_entity,destination_id FROM whmcstofossbilling_identity WHERE source_id=? AND table_name=? AND source_key=?', [$source, $row['table_name'], $row['source_key']]);
        }

        return ['records' => $rows, 'next_offset' => count($rows) === 25 ? $offset + 25 : null];
    }
}
