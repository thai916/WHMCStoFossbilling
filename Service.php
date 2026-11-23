<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling;

use Box\Mod\Whmcstofossbilling\Destination\NativeWriter;
use Box\Mod\Whmcstofossbilling\Destination\Store;
use Box\Mod\Whmcstofossbilling\Destination\VisibilityRepair;
use Box\Mod\Whmcstofossbilling\Installation\Schema;
use Box\Mod\Whmcstofossbilling\Mapping\Profile;
use Box\Mod\Whmcstofossbilling\Migration\Engine;
use Box\Mod\Whmcstofossbilling\Migration\BatchSizer;
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

    public function destinationStatus(?int $source = null, string $mode = 'archive'): array
    {
        if (!$this->store()->schemaReady()) return ['ready' => false, 'schema_required' => true, 'unrelated_tables' => [], 'tables' => []];
        if (!in_array($mode, ['archive', 'reference_native', 'historical_native'], true)) throw new SafeException('Unknown migration mode.');
        if ($source !== null) $this->store()->source($source);
        $scope = $mode === 'archive' ? null : $source;
        $status = \Box\Mod\Whmcstofossbilling\Destination\FreshCheck::check($this->store(), $scope);
        $status['tables'] = \Box\Mod\Whmcstofossbilling\Destination\Overwrite::inspect($this->store(), $scope);

        return $status;
    }

    public function backup(string $operation, #[\SensitiveParameter] array $input, int $owner): array
    {
        $this->requirements();
        $job = new \Box\Mod\Whmcstofossbilling\Source\Backup\Job((string) Config::getProperty('info.salt'));
        if ($operation === 'begin') return $job->begin($input, $owner);
        $id = (string) ($input['backup_id'] ?? '');
        if (in_array($operation, ['chunk', 'prepare'], true)) return $job->operate($id, $owner, $operation, $input);
        $state = $job->read($id, $owner);
        if ($operation === 'remove') {
            if (($input['confirmation'] ?? '') !== 'REMOVE PRIVATE BACKUP') throw new SafeException('Confirm private backup removal first.');
            $locks = [];
            try {
                foreach ($this->store()->all('SELECT id,credentials FROM whmcstofossbilling_source ORDER BY id') as $source) {
                    $options = $this->vault()->open($source['credentials']);
                    if (($options['backup_id'] ?? '') !== $id) continue;
                    $name = 'whmcstofossbilling-source-' . $source['id']; $this->store()->lock($name); $locks[] = $name;
                    if ($this->store()->one("SELECT id FROM whmcstofossbilling_run WHERE source_id=? AND status IN ('running','paused') LIMIT 1", [$source['id']])) throw new SafeException('This backup is required by an unfinished run. Finish or cancel it before removing its source files.');
                }
                $job->remove($id, $owner);
            } finally {
                foreach (array_reverse($locks) as $name) $this->store()->unlock($name);
            }
            return ['removed' => true];
        }
        if ($operation !== 'source' || $state['phase'] !== 'ready') throw new SafeException('Finish preparing the SQL backup before selecting it as a source.');
        $options = ['kind' => 'sql_file', 'backup_id' => $id, 'prefix' => $state['prefix'], 'source_timezone' => $state['source_timezone'], 'timezone_detection' => \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::selected($state['source_timezone'])];
        $schema = $state['schema'];
        $fingerprint = hash('sha256', 'sql-backup:' . $id);
        $this->store()->execute('INSERT INTO whmcstofossbilling_source (fingerprint,label,credentials,schema_json,mapping_json,source_version,tested_at,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE tested_at=UTC_TIMESTAMP()', [$fingerprint, 'Uploaded WHMCS SQL backup', $this->vault()->seal($options), Store::json($schema), Store::json(Profile::defaults($schema)), $state['version']]);
        $source = (int) $this->store()->one('SELECT id FROM whmcstofossbilling_source WHERE fingerprint=?', [$fingerprint])['id'];
        return $this->mapping($source);
    }

    public function mapping(int $source): array
    {
        $row = $this->store()->source($source);
        $schema = json_decode($row['schema_json'], true, 128, JSON_THROW_ON_ERROR);
        $profile = json_decode($row['mapping_json'], true, 128, JSON_THROW_ON_ERROR);
        $options = $this->vault()->open($row['credentials']);

        return [
            'source_id' => $source, 'label' => $row['label'], 'version' => $row['source_version'],
            'source_kind' => $options['kind'] ?? 'database', 'backup_id' => $options['backup_id'] ?? null,
            'source_available' => ($options['kind'] ?? '') !== 'sql_file' || is_file(\Box\Mod\Whmcstofossbilling\Source\Backup\Job::directory($options['backup_id']) . '/state'),
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
        $reader = \Box\Mod\Whmcstofossbilling\Source\ReaderFactory::open($this->vault()->open($row['credentials']));
        if (Store::json($reader->inspect()) !== $row['schema_json']) {
            throw new SafeException('The source schema changed while analyzing ' . $name . '. Counts for this table cannot be refreshed. Test the connection again, then review and analyze the selected tables.');
        }
        $bounds = $reader->bounds($name, $definition);
        $this->store()->execute('INSERT INTO whmcstofossbilling_analysis (source_id,table_name,expected,maximum,schema_checksum,analyzed_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE expected=VALUES(expected),maximum=VALUES(maximum),schema_checksum=VALUES(schema_checksum),analyzed_at=UTC_TIMESTAMP()', [$source, $name, $bounds['count'], $bounds['maximum'], hash('sha256', $row['schema_json'])]);

        return ['table' => $name, ...$bounds];
    }

    public function prepareAnalysis(array $input): array
    {
        $source = $this->store()->source((int) ($input['source_id'] ?? 0));
        $schema = json_decode($source['schema_json'], true, 128, JSON_THROW_ON_ERROR);
        $target = (string) ($input['target_mode'] ?? 'archive');
        if (!in_array($target, ['archive', 'reference_native', 'historical_native'], true)) throw new SafeException('Unknown analysis target mode.');
        $selection = $input['tables'] ?? [];
        if (!is_array($selection) || count($selection) > 500) throw new SafeException('Invalid source selection.');
        foreach ($selection as $name) {
            if (!is_string($name) || !isset($schema[$name])) throw new SafeException('Unknown source selection.');
        }
        if ($target !== 'archive') $selection = Dependencies::expand($schema, $selection);
        $selection = array_values(array_unique($selection));

        return ['tables' => $selection, 'issues' => Planner::analysisIssues($this->store(), (int) $source['id'], $schema, $selection)];
    }

    public function existingRun(array $input): ?array
    {
        $source = $this->store()->source((int) ($input['source_id'] ?? 0));
        $existing = $this->store()->one("SELECT id FROM whmcstofossbilling_run WHERE source_id=? AND mode<>'dry_run' AND status IN ('running','paused') ORDER BY created_at,id LIMIT 1", [$source['id']]);
        if (!$existing) return null;
        $saved = $this->store()->run($existing['id']);
        $schema = json_decode($source['schema_json'], true, 128, JSON_THROW_ON_ERROR);
        $selection = $input['tables'] ?? [];
        if (!is_array($selection) || count($selection) > 500) throw new SafeException('Invalid source selection.');
        foreach ($selection as $name) {
            if (!is_string($name) || !isset($schema[$name])) throw new SafeException('Unknown source selection.');
        }
        $mode = (string) ($input['mode'] ?? 'archive');
        if (!in_array($mode, ['archive', 'reference_native', 'historical_native'], true)) throw new SafeException('Unknown migration mode.');
        if ($mode !== 'archive') $selection = Dependencies::expand($schema, $selection);
        $selection = array_values(array_unique($selection));
        sort($selection);
        $plan = json_decode($saved['plan_json'], true, 128, JSON_THROW_ON_ERROR);
        $savedTables = array_keys($plan['tables']);
        sort($savedTables);
        $differences = [];
        if ($mode !== $saved['mode']) $differences[] = 'execution mode';
        if ($selection !== $savedTables) $differences[] = 'selected tables';
        if (($plan['invalid_date_fallback'] ?? null) !== (($input['invalid_date_fallback'] ?? '') === '1900' ? NativeWriter::DATE_FALLBACK : null)) $differences[] = 'invalid-date policy';
        try {
            $mapping = Profile::validate($input['profile'] ?? json_decode($source['mapping_json'], true, 128, JSON_THROW_ON_ERROR), $schema);
            if (Store::json($mapping) !== $saved['mapping_json']) $differences[] = 'field mappings';
        } catch (SafeException) {
            $differences[] = 'field mappings need review';
        }
        if ($source['schema_json'] !== $saved['schema_json']) $differences[] = 'source schema';
        $options = $this->vault()->open($source['credentials']);
        if (($options['source_timezone'] ?? '') !== ($plan['source_timezone'] ?? '')) $differences[] = 'source timezone';

        return ['run' => $this->status($existing['id']), 'differences' => $differences];
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
        if ($mode !== 'dry_run' && (($input['confirmation'] ?? '') !== 'PRESERVE HISTORY' || empty($input['accept_limitations']))) {
            throw new SafeException('Acknowledge the migration limitations and type PRESERVE HISTORY.');
        }
        $overwrite = false;
        if ($mode !== 'dry_run') {
            $phrase = (string) ($input['overwrite_confirmation'] ?? '');
            $acknowledged = !empty($input['overwrite_acknowledged']);
            if ($phrase !== '' && !$acknowledged) throw new SafeException('Confirm the destructive overwrite checkbox before typing the overwrite phrase.');
            if ($acknowledged && $phrase !== \Box\Mod\Whmcstofossbilling\Destination\Overwrite::CONFIRMATION) throw new SafeException('Type OVERWRITE FOSSBILLING DATA exactly to overwrite existing FOSSBilling records.');
            $fresh = \Box\Mod\Whmcstofossbilling\Destination\FreshCheck::check($this->store(), $mode === 'archive' ? null : (int) $source['id']);
            if (!$fresh['ready'] && $phrase !== \Box\Mod\Whmcstofossbilling\Destination\Overwrite::CONFIRMATION) {
                throw new SafeException('Use a fresh FOSSBilling installation, resume the same migration, or explicitly confirm OVERWRITE FOSSBILLING DATA to permanently delete the unrelated business records found in: ' . implode(', ', $fresh['unrelated_tables']) . '. No records or table structures were changed.');
            }
            $overwrite = $acknowledged && $phrase === \Box\Mod\Whmcstofossbilling\Destination\Overwrite::CONFIRMATION;
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
        $plan['source_security_mode'] = ($options['kind'] ?? '') === 'sql_file' ? 'sql_file' : ConnectionOptions::validate($options, true)['security_mode'];
        if (($options['kind'] ?? '') === 'sql_file') $plan['source_backup_id'] = $options['backup_id'];
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
            if (isset($plan['source_backup_id'])) \Box\Mod\Whmcstofossbilling\Source\ReaderFactory::open($options)->inspect();
            $store->pdo->beginTransaction();
            $reset = $overwrite ? \Box\Mod\Whmcstofossbilling\Destination\Overwrite::apply($store) : null;
            $plan['destination_overwrite'] = $reset;
            $backupReference = $mode === 'dry_run' ? 'dry run'
                : ($reset === null ? 'Fresh installation / migration-owned records verified' : 'Confirmed destructive overwrite of blocking FOSSBilling records');
            $store->execute('INSERT INTO whmcstofossbilling_run (id,source_id,status,mode,schema_json,mapping_json,plan_json,owner_id,backup_reference,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$id, $source['id'], 'paused', $mode, Store::json($schema), Store::json($profile), Store::json($plan), $admin, $backupReference]);
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

    /**
     * Runs several bounded batches inside one API request. FOSSBilling counts
     * every authenticated admin call twice (pre-auth IP plus account), so
     * browser-side per-batch polling exhausts the shared hourly limiter.
     */
    public function drive(string $run, string $request, int $budgetMs = 15000, int $limit = BatchSizer::START_ROWS): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $request)) throw new SafeException('Invalid drive request identifier.');
        if ($budgetMs < 1000 || $budgetMs > 15000 || $limit < BatchSizer::MIN_ROWS || $limit > BatchSizer::MAX_ROWS) throw new SafeException('Invalid drive budget or batch size.');
        $store = $this->store();
        $cached = $store->one('SELECT result_json FROM whmcstofossbilling_batch WHERE run_id=? AND request_id=?', [$run, $request]);
        if ($cached) return json_decode($cached['result_json'], true, 512, JSON_THROW_ON_ERROR);

        // The most recent durable batch result carries the last measured sizing
        // decision. Replays of this exact drive request return the cached result
        // above, so an idempotent retry cannot double-apply a batch.
        $latest = $store->one('SELECT result_json FROM whmcstofossbilling_batch WHERE run_id=? ORDER BY id DESC LIMIT 1', [$run]);
        $previous = $latest ? json_decode($latest['result_json'], true, 128, JSON_THROW_ON_ERROR) : null;
        $limit = BatchSizer::initial($limit, $previous);
        $currentLimit = $limit;

        $engine = new Engine($store, $this->vault());
        $processed = 0;
        $batches = 0;
        $started = microtime(true);
        $status = $store->run($run)['status'];
        while ($status === 'running' && $batches < 4 && (microtime(true) - $started) * 1000 < $budgetMs) {
            $derived = substr(hash('sha256', $request . ':' . $batches), 0, 32);
            $result = $engine->batch($run, $derived, $currentLimit);
            $processed += (int) ($result['batch_processed'] ?? 0);
            $status = (string) $result['status'];
            if (isset($result['next_limit']) && is_numeric($result['next_limit'])) {
                $currentLimit = BatchSizer::clamp((int) $result['next_limit']);
            }
            ++$batches;
            // Reconciliation and credit publication report zero processed rows;
            // return instead of spinning through the same no-op inside one call.
            if ($result['batch_processed'] === 0) break;
        }

        $result = $this->status($run);
        $result['batch_processed'] = $processed;
        $result['batches'] = $batches;
        $result['batch_limit'] = $limit;
        $result['next_limit'] = $currentLimit;
        $store->execute('INSERT INTO whmcstofossbilling_batch (run_id,request_id,result_json,created_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$run, $request, Store::json($result)]);

        return $result;
    }

    public function retry(string $run): array
    {
        return (new Engine($this->store(), $this->vault()))->retry($run);
    }

    public function repairVisibility(array $input): array
    {
        return (new VisibilityRepair($this->store()))->run((string) ($input['confirmation'] ?? ''), [
            'run_id' => (string) ($input['run_id'] ?? ''),
            'source_id' => (int) ($input['source_id'] ?? 0),
        ]);
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
