<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Migration;

use Box\Mod\Whmcstofossbilling\Archive\Repository;
use Box\Mod\Whmcstofossbilling\Destination\NativeWriter;
use Box\Mod\Whmcstofossbilling\Destination\Store;
use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Security\Sensitive;
use Box\Mod\Whmcstofossbilling\Security\Vault;
use Box\Mod\Whmcstofossbilling\Source\Reader;
use Box\Mod\Whmcstofossbilling\Finance\Reconciler;
use Box\Mod\Whmcstofossbilling\Destination\CreditPublisher;
use Box\Mod\Whmcstofossbilling\Mapping\PreviewValidator;

final class Engine
{
    public function __construct(private readonly Store $store, private readonly Vault $vault)
    {
    }

    public function batch(string $runId, string $requestId, int $limit = BatchSizer::START_ROWS): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $requestId) || $limit < BatchSizer::MIN_ROWS || $limit > BatchSizer::MAX_ROWS) {
            throw new SafeException('Invalid batch request identifier or size.');
        }
        $run = $this->store->run($runId);
        // Serialize all runs for a source, not merely an individual browser or run.
        $lock = 'whmcstofossbilling-source-' . $run['source_id'];
        $this->store->lock($lock);
        try {
            $previous = $this->store->one('SELECT result_json FROM whmcstofossbilling_batch WHERE run_id=? AND request_id=?', [$runId, $requestId]);
            if ($previous) {
                return json_decode($previous['result_json'], true, 32, JSON_THROW_ON_ERROR);
            }
            $run = $this->store->run($runId);
            if ($run['status'] !== 'running') {
                throw new SafeException('This run is not running. Resume it explicitly before scheduling batches.');
            }
            $plan = json_decode($run['plan_json'], true, 128, JSON_THROW_ON_ERROR);
            if (in_array($run['mode'], ['reference_native', 'historical_native'], true) && ($plan['adapter_revision'] ?? 1) !== 2) {
                throw new SafeException('Native adapters were upgraded. Create a new reviewed run; an old authorization cannot enable newly added import categories.');
            }
            if ($this->store->verifyDestination()['fingerprint'] !== $plan['destination_fingerprint']) {
                throw new SafeException('Destination schema changed after preflight. Create a new migration plan.');
            }
            if (in_array($run['mode'], ['reference_native', 'historical_native'], true) && isset($plan['destination_timezone']) && \FOSSBilling\Config::getProperty('i18n.timezone', 'UTC') !== $plan['destination_timezone']) throw new SafeException('Destination timezone changed after planning. Create a new reviewed plan.');
            $schema = json_decode($run['schema_json'], true, 128, JSON_THROW_ON_ERROR);
            \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::assertNativeStorage($schema, array_keys($plan['tables']), $run['mode'] === 'dry_run' ? ($plan['target_mode'] ?? 'reference_native') : $run['mode']);
            $mapping = json_decode($run['mapping_json'], true, 128, JSON_THROW_ON_ERROR);
            $cursor = null;
            foreach (array_keys($plan['tables']) as $table) {
                $candidate = $this->store->one("SELECT * FROM whmcstofossbilling_cursor WHERE run_id=? AND table_name=? AND status='pending'", [$runId, $table]);
                if ($candidate) {
                    $cursor = $candidate;
                    break;
                }
            }
            if (!$cursor) {
                if (!(new CreditPublisher($this->store))->batch($run)) return $this->result($runId, $requestId, 0);
                if ((new Reconciler($this->store))->batch($run)) {
                    $this->finalize($runId, $run['mode']);
                }
                return $this->result($runId, $requestId, 0);
            }
            $source = $this->store->source((int) $run['source_id']);
            $options = $this->vault->open($source['credentials']);
            \Box\Mod\Whmcstofossbilling\Source\ReaderFactory::assertPlan($options, $plan);
            $reader = \Box\Mod\Whmcstofossbilling\Source\ReaderFactory::open($options);
            if (Store::json($reader->inspect()) !== Store::json($schema)) {
                throw new SafeException('Source schema changed after preflight. Stop and review a new plan.');
            }
            $name = $cursor['table_name'];
            $definition = $schema[$name];
            $batchStarted = microtime(true);
            $page = $reader->page($name, $definition, $cursor['after_id'], $cursor['maximum'], $limit);
            $rows = $page['rows'];
            $native = new NativeWriter($this->store, (int) $source['id'], $options['prefix'], $plan['source_timezone'] ?? null, $plan['invalid_date_fallback'] ?? null, $plan['missing_invoice_currency'] ?? null);
            $archive = new Repository($this->store);
            // The local five-second work cap starts after extraction; the total
            // elapsed measurement below also includes the bounded source read.
            $started = microtime(true);
            $processed = 0;
            foreach ($rows as $row) {
                // Bound local work; remote reads have a separate SQL deadline on MySQL.
                if ($processed > 0 && microtime(true) - $started > 5) {
                    break;
                }
                $key = (string) $row[$definition['key']];
                $this->store->pdo->beginTransaction();
                try {
                    if (!empty($row['__wm_missing'])) throw new SafeException('Source record disappeared between reads. Original contents were not available; manual reconciliation is required.');
                    if (!empty($row['__wm_oversized'])) throw new SafeException('Source record exceeds the safe extraction byte/field budget. A reviewed large-object extractor is required; no truncated values were imported.');
                    // Source reads are outside destination transactions.
                    $rules = $mapping['fields'][$name];
                    if ($run['mode'] === 'dry_run') {
                        if (isset($plan['native_entities'][$definition['suffix']])) {
                            PreviewValidator::validate($definition['suffix'], $row, $plan['source_timezone'] ?? null);
                        }
                        if ($definition['suffix'] === 'clients' && isset($plan['native_entities']['clients'])) {
                            $native->validateClientPreview($row, $rules);
                        }
                        $status = 'previewed';
                    } else {
                        $preserved = $archive->preserve((int) $source['id'], $name, $key, $row, $definition, $rules, $runId);
                        // Archive survives a native adapter failure; no half-created native aggregate survives.
                        $this->store->execute('SAVEPOINT native_record');
                        try {
                            $status = in_array($run['mode'], ['reference_native', 'historical_native'], true) && isset($plan['native_entities'][$definition['suffix']])
                                ? $native->import($definition['suffix'], $row, $rules, $preserved['checksum'], $runId)
                                : ($preserved['changed'] ? 'archived' : 'skipped');
                        } catch (\Throwable $error) {
                            $this->store->execute('ROLLBACK TO SAVEPOINT native_record');
                            $this->attempt($runId, $name, $key, 'failed', Sensitive::error($error), false);
                            $status = 'failed';
                        }
                    }
                    if ($status !== 'failed') {
                        $message = match ($status) {
                            'date_fallback' => $native->dateFallbackOriginal(),
                            'currency_inference' => $native->currencyInference(),
                            default => null,
                        };
                        $this->attempt($runId, $name, $key, $status, $message, false);
                    }
                    $column = match ($status) {
                        'imported', 'date_fallback', 'currency_inference' => 'imported', 'skipped' => 'skipped', 'failed' => 'failed', default => 'archived',
                    };
                    // Preview counts are separate in the report and are never described as actual archives.
                    $this->store->execute("UPDATE whmcstofossbilling_cursor SET after_id=?,processed=processed+1,`$column`=`$column`+1 WHERE id=?", [$key, $cursor['id']]);
                    $this->store->pdo->commit();
                } catch (\Throwable $error) {
                    if ($this->store->pdo->inTransaction()) {
                        $this->store->pdo->rollBack();
                    }
                    // A failed row is durable and advances the cursor; the run cannot become complete.
                    $this->store->pdo->beginTransaction();
                    try {
                        $this->attempt($runId, $name, $key, 'failed', Sensitive::error($error), false);
                        $this->store->execute('UPDATE whmcstofossbilling_cursor SET after_id=?,processed=processed+1,failed=failed+1 WHERE id=?', [$key, $cursor['id']]);
                        $this->store->pdo->commit();
                    } catch (\Throwable $persistError) {
                        $this->store->pdo->rollBack();
                        throw $persistError;
                    }
                }
                ++$processed;
            }
            if ($page['exhausted'] && $processed === count($rows)) {
                $this->store->execute("UPDATE whmcstofossbilling_cursor SET status='scanned' WHERE id=?", [$cursor['id']]);
            }
            $this->store->execute('UPDATE whmcstofossbilling_run SET updated_at=UTC_TIMESTAMP(),last_batch_at=UTC_TIMESTAMP() WHERE id=?', [$runId]);

            return $this->result($runId, $requestId, $processed, [
                'requested_limit' => $limit,
                'elapsed_ms' => max(1, (int) round((microtime(true) - $batchStarted) * 1000)),
                'page_bytes' => (int) ($page['bytes'] ?? 0),
                'page_exhausted' => !empty($page['exhausted']) && $processed === count($rows),
            ]);
        } finally {
            $this->store->unlock($lock);
        }
    }

    public function retry(string $runId, int $limit = 5): array
    {
        $run = $this->store->run($runId);
        $lock = 'whmcstofossbilling-source-' . $run['source_id'];
        $this->store->lock($lock);
        try {
            $run = $this->store->run($runId);
            if ($run['status'] !== 'requires_action' || $limit < 1 || $limit > 5) {
                throw new SafeException('Retries are available only after a scan finishes with exceptions, at most five records per request.');
            }
            $source = $this->store->source((int) $run['source_id']);
            $options = $this->vault->open($source['credentials']);
            \Box\Mod\Whmcstofossbilling\Source\ReaderFactory::assertPlan($options, json_decode($run['plan_json'], true, 128, JSON_THROW_ON_ERROR));
            $reader = \Box\Mod\Whmcstofossbilling\Source\ReaderFactory::open($options);
            $schema = json_decode($run['schema_json'], true, 128, JSON_THROW_ON_ERROR);
            if (Store::json($reader->inspect()) !== $run['schema_json']) {
                throw new SafeException('Source schema changed; retries are blocked pending a new plan.');
            }
            $plan = json_decode($run['plan_json'], true, 128, JSON_THROW_ON_ERROR);
            if (in_array($run['mode'], ['reference_native', 'historical_native'], true) && ($plan['adapter_revision'] ?? 1) !== 2) throw new SafeException('Native adapter revision changed. A new reviewed run is required.');
            \Box\Mod\Whmcstofossbilling\Source\SourceTimezone::assertNativeStorage($schema, array_keys($plan['tables']), $run['mode'] === 'dry_run' ? ($plan['target_mode'] ?? 'reference_native') : $run['mode']);
            if (isset($plan['destination_timezone']) && \FOSSBilling\Config::getProperty('i18n.timezone', 'UTC') !== $plan['destination_timezone']) throw new SafeException('Destination timezone changed after planning. Retrying native history is blocked.');
            if ($this->store->verifyDestination()['fingerprint'] !== $plan['destination_fingerprint']) {
                throw new SafeException('Destination schema changed; retries are blocked pending a new plan.');
            }
            $mapping = json_decode($run['mapping_json'], true, 128, JSON_THROW_ON_ERROR);
            $native = new NativeWriter($this->store, (int) $source['id'], $options['prefix'], $plan['source_timezone'] ?? null, $plan['invalid_date_fallback'] ?? null, $plan['missing_invoice_currency'] ?? null);
            $archive = new Repository($this->store);
            $failures = $this->store->all("SELECT * FROM whmcstofossbilling_attempt WHERE run_id=? AND status='failed' AND attempts<5 ORDER BY id LIMIT " . $limit, [$runId]);
            $succeeded = 0;
            foreach ($failures as $failure) {
                $name = $failure['table_name'];
                $key = $failure['source_key'];
                $definition = $schema[$name];
                $rows = $reader->batch($name, $definition, bcsub($key, '1', 0), $key, 1);
                $this->store->pdo->beginTransaction();
                try {
                    if (count($rows) !== 1) {
                        throw new SafeException('Source record no longer exists. Historical evidence retained; manual action required.');
                    }
                    $row = $rows[0];
                    if (!empty($row['__wm_missing'])) throw new SafeException('Source record disappeared during retry.');
                    if (!empty($row['__wm_oversized'])) throw new SafeException('Source record exceeds the safe extraction budget. No truncated values were imported.');
                    $rules = $mapping['fields'][$name];
                    if ($run['mode'] === 'dry_run') {
                        if (isset($plan['native_entities'][$definition['suffix']])) PreviewValidator::validate($definition['suffix'], $row, $plan['source_timezone'] ?? null);
                        if ($definition['suffix'] === 'clients' && isset($plan['native_entities']['clients'])) {
                            $native->validateClientPreview($row, $rules);
                        }
                        $status = 'previewed';
                    } else {
                        $preserved = $archive->preserve((int) $source['id'], $name, $key, $row, $definition, $rules, $runId);
                        $status = in_array($run['mode'], ['reference_native', 'historical_native'], true) && isset($plan['native_entities'][$definition['suffix']])
                            ? $native->import($definition['suffix'], $row, $rules, $preserved['checksum'], $runId)
                            : ($preserved['changed'] ? 'archived' : 'skipped');
                    }
                    $message = match ($status) {
                        'date_fallback' => $native->dateFallbackOriginal(),
                        'currency_inference' => $native->currencyInference(),
                        default => null,
                    };
                    $this->attempt($runId, $name, $key, $status, $message, false);
                    $column = match ($status) {
                        'imported', 'date_fallback', 'currency_inference' => 'imported', 'skipped' => 'skipped', default => 'archived',
                    };
                    $this->store->execute("UPDATE whmcstofossbilling_cursor SET failed=failed-1,`$column`=`$column`+1 WHERE run_id=? AND table_name=?", [$runId, $name]);
                    $this->store->pdo->commit();
                    ++$succeeded;
                } catch (\Throwable $error) {
                    $this->store->pdo->rollBack();
                    $this->attempt($runId, $name, $key, 'failed', Sensitive::error($error), false);
                }
            }
            $creditRetries = (new CreditPublisher($this->store))->retry($run, $limit - count($failures));
            $succeeded += $creditRetries['succeeded'];
            if ($succeeded > 0) {
                $this->store->execute("UPDATE whmcstofossbilling_reconcile_state SET after_id=0,status='scanning' WHERE run_id=?", [$runId]);
                $this->store->execute("UPDATE whmcstofossbilling_run SET status='paused',updated_at=UTC_TIMESTAMP() WHERE id=?", [$runId]);
            } else {
                $this->finalize($runId, $run['mode']);
            }

            return ['run_id' => $runId, 'attempted' => count($failures) + $creditRetries['attempted'], 'status' => $this->store->run($runId)['status']];
        } finally {
            $this->store->unlock($lock);
        }
    }

    private function attempt(string $run, string $table, string $key, string $status, ?string $message, bool $retryable): void
    {
        $errorType = match ($status) {
            'date_fallback' => 'invalid_historical_date',
            'currency_inference' => 'currency_inference',
            default => $message !== null ? 'validation_or_adapter' : null,
        };
        $this->store->execute('INSERT INTO whmcstofossbilling_attempt (run_id,table_name,source_key,status,error_type,message,retryable,created_at,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=VALUES(status),error_type=VALUES(error_type),message=VALUES(message),retryable=VALUES(retryable),attempts=attempts+1,updated_at=UTC_TIMESTAMP()', [$run, $table, $key, $status, $errorType, $message, (int) $retryable]);
    }

    private function finalize(string $run, string $mode): void
    {
        $counts = $this->store->one('SELECT COALESCE(SUM(failed),0) AS failed,COALESCE(SUM(expected),0) AS expected,COALESCE(SUM(processed),0) AS processed FROM whmcstofossbilling_cursor WHERE run_id=?', [$run]);
        $creditFailures = $this->store->one("SELECT COUNT(*) AS n FROM whmcstofossbilling_credit_result WHERE run_id=? AND status='failed'", [$run])['n'];
        $financialFailures = $mode === 'historical_native' && in_array((new Reconciler($this->store))->summary($run)['status'], ['exceptions', 'in_progress'], true);
        $status = $counts['failed'] > 0 || $counts['expected'] !== $counts['processed'] || $creditFailures || $financialFailures ? 'requires_action' : ($mode === 'dry_run' ? 'preview_finished' : 'partial_archived');
        // 0.1 deliberately has no "complete migration" state: required native categories are blocked.
        $this->store->execute('UPDATE whmcstofossbilling_run SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?', [$status, $run]);
    }

    private function result(string $run, string $request, int $processed, array $metrics = []): array
    {
        $result = ['run_id' => $run, 'status' => $this->store->run($run)['status'], 'batch_processed' => $processed];
        if ($metrics !== []) {
            $limit = BatchSizer::clamp((int) ($metrics['requested_limit'] ?? 0));
            $sizingInput = $metrics + ['batch_processed' => $processed];
            $result['batch_limit'] = $limit;
            $result['requested_limit'] = $limit;
            $result['elapsed_ms'] = max(0, (int) ($metrics['elapsed_ms'] ?? 0));
            $result['page_bytes'] = max(0, (int) ($metrics['page_bytes'] ?? 0));
            $result['page_exhausted'] = (bool) ($metrics['page_exhausted'] ?? false);
            $result['next_limit'] = BatchSizer::next($limit, $sizingInput);
        }
        $this->store->execute('INSERT INTO whmcstofossbilling_batch (run_id,request_id,result_json,created_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$run, $request, Store::json($result)]);

        return $result;
    }
}
