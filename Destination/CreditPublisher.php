<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Destination;

use Box\Mod\Whmcstofossbilling\Archive\Sanitizer;
use Box\Mod\Whmcstofossbilling\Finance\Decimal;
use Box\Mod\Whmcstofossbilling\Mapping\EventDate;
use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Security\Sensitive;

/** Publish only a complete, verified client credit aggregate. Never synthesize an opening balance. */
final class CreditPublisher
{
    public function __construct(private readonly Store $store)
    {
    }

    public function batch(array $run, int $limit = 5): bool
    {
        if ($run['mode'] !== 'historical_native') return true;
        $plan = json_decode($run['plan_json'], true, 128, JSON_THROW_ON_ERROR);
        $prefix = $plan['source_prefix'];
        if (!isset($plan['tables'][$prefix . 'credit'])) return true;
        $this->store->execute('INSERT IGNORE INTO whmcstofossbilling_credit_state (run_id) VALUES (?)', [$run['id']]);
        $state = $this->store->one('SELECT * FROM whmcstofossbilling_credit_state WHERE run_id=?', [$run['id']]);
        if ($state['status'] === 'finished') return true;
        $rows = $this->store->all('SELECT * FROM whmcstofossbilling_snapshot WHERE run_id=? AND table_name=? AND id>? ORDER BY id LIMIT ' . min(5, max(1, $limit)), [$run['id'], $prefix . 'clients', $state['after_id']]);
        $started = microtime(true);
        foreach ($rows as $client) {
            $this->publishSafely($run, $plan, $client);
            $this->store->execute('UPDATE whmcstofossbilling_credit_state SET after_id=? WHERE run_id=?', [$client['id'], $run['id']]);
            if (microtime(true) - $started > 5) return false;
        }
        if (count($rows) < min(5, max(1, $limit))) {
            $this->store->execute("UPDATE whmcstofossbilling_credit_state SET status='finished' WHERE run_id=?", [$run['id']]);
            return true;
        }

        return false;
    }

    public function retry(array $run, int $limit = 5): array
    {
        if ($run['mode'] !== 'historical_native' || $limit < 1) return ['attempted' => 0, 'succeeded' => 0];
        $plan = json_decode($run['plan_json'], true, 128, JSON_THROW_ON_ERROR);
        $failures = $this->store->all("SELECT s.* FROM whmcstofossbilling_credit_result r JOIN whmcstofossbilling_snapshot s ON s.run_id=r.run_id AND s.table_name=? AND s.source_key=r.source_key WHERE r.run_id=? AND r.status='failed' AND r.attempts<5 ORDER BY s.id LIMIT " . min(5, $limit), [($plan['source_prefix'] ?? '') . 'clients', $run['id']]);
        $succeeded = 0;
        foreach ($failures as $client) if ($this->publishSafely($run, $plan, $client)) ++$succeeded;

        return ['attempted' => count($failures), 'succeeded' => $succeeded];
    }

    private function publishSafely(array $run, array $plan, array $client): bool
    {
        $previous = $this->store->one('SELECT status FROM whmcstofossbilling_credit_result WHERE run_id=? AND source_key=?', [$run['id'], $client['source_key']]);
        if ($previous && $previous['status'] === 'verified') return true;
        $this->store->pdo->beginTransaction();
        try {
            $this->publish($run, $plan, $client);
            $this->store->execute("INSERT INTO whmcstofossbilling_credit_result (run_id,source_key,status) VALUES (?,?,'verified') ON DUPLICATE KEY UPDATE status='verified',message=NULL,attempts=attempts+1", [$run['id'], $client['source_key']]);
            $this->store->pdo->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->store->pdo->inTransaction()) $this->store->pdo->rollBack();
            $this->store->execute("INSERT INTO whmcstofossbilling_credit_result (run_id,source_key,status,message) VALUES (?,?,'failed',?) ON DUPLICATE KEY UPDATE status='failed',message=VALUES(message),attempts=attempts+1", [$run['id'], $client['source_key'], Sensitive::error($error)]);
            return false;
        }
    }

    private function publish(array $run, array $plan, array $record): void
    {
        $prefix = $plan['source_prefix'];
        $cursor = $this->store->one('SELECT status,failed,expected,processed FROM whmcstofossbilling_cursor WHERE run_id=? AND table_name=?', [$run['id'], $prefix . 'credit']);
        if (!$cursor || $cursor['status'] !== 'scanned' || (int) $cursor['failed'] !== 0 || $cursor['expected'] !== $cursor['processed']) throw new SafeException('Complete credit extraction is required before native balance publication.');
        $client = json_decode($record['payload'], true, 128, JSON_THROW_ON_ERROR);
        $balance = Decimal::money($client['credit'] ?? '');
        $p = new Persistence($this->store, (int) $run['source_id'], $prefix);
        if (!$p->unchanged('clients', $record['source_key'], 'client', $record['checksum'])) throw new SafeException('Client has no unchanged, verified native identity for this credit snapshot.');
        $clientId = $p->resolve('clients', $record['source_key'], 'client');
        // Any actionable unpaid invoice could consume a balance concurrently through core cron.
        if ($this->store->one("SELECT id FROM invoice WHERE client_id=? AND status='unpaid' AND approved=1 LIMIT 1", [$clientId])) throw new SafeException('Client has an actionable unpaid destination invoice. Credit automation isolation cannot be verified.');
        $movements = $this->store->all("SELECT s.* FROM whmcstofossbilling_snapshot_link l JOIN whmcstofossbilling_snapshot s ON s.id=l.snapshot_id WHERE l.run_id=? AND l.table_name=? AND l.field_name='clientid' AND l.target_key=? ORDER BY s.id LIMIT 201", [$run['id'], $prefix . 'credit', $record['source_key']]);
        if (count($movements) > 200) throw new SafeException('Large client credit ledger requires staged aggregate publication.');
        $total = '0.00';
        $rows = [];
        foreach ($movements as $movement) {
            $data = json_decode($movement['payload'], true, 128, JSON_THROW_ON_ERROR);
            $amount = Decimal::money($data['amount'] ?? '');
            $total = bcadd($total, $amount, 2);
            $rows[] = [$movement, $data, $amount];
        }
        if ($total !== $balance) throw new SafeException('Historical credit movements do not equal the source closing balance. No opening adjustment or partial native ledger was posted.');
        $existing = $this->store->all('SELECT id FROM client_balance WHERE client_id=? LIMIT 201', [$clientId]);
        if (count($existing) > 200) throw new SafeException('Destination balance ledger is too large for verified bounded publication.');
        foreach ($existing as $entry) {
            $mapping = $this->store->one('SELECT * FROM whmcstofossbilling_identity WHERE source_id=? AND table_name=? AND destination_entity=? AND destination_id=?', [$run['source_id'], $prefix . 'credit', 'client_balance', $entry['id']]);
            if (!$mapping) throw new SafeException('Client has a destination credit movement not created by this source import. Automatic balance merge refused.');
            if (!in_array($mapping['source_key'], array_column($movements, 'source_key'), true)) throw new SafeException('A prior native credit movement is absent from this source snapshot. Destructive incremental ledger changes require manual reconciliation.');
            $p->verify($mapping);
        }
        foreach ($rows as [$movement, $data, $amount]) {
            if ($p->unchanged('credit', $movement['source_key'], 'client_balance', $movement['checksum'])) continue;
            $date = EventDate::utc($data['date'] ?? null, $plan['source_timezone'] ?? null) ?? throw new SafeException('Credit movement date is missing.');
            $id = $p->insert('client_balance', [
                'client_id' => $clientId, 'type' => 'whmcs_history', 'rel_id' => $movement['source_key'],
                'invoice_item_id' => null, 'amount' => $amount,
                'description' => Sanitizer::text((string) ($data['description'] ?? '')), 'created_at' => $date, 'updated_at' => $date,
            ]);
            $p->track('credit', $movement['source_key'], 'client_balance', $id, $movement['checksum'], $run['id']);
            $attempt = $this->store->one('SELECT status FROM whmcstofossbilling_attempt WHERE run_id=? AND table_name=? AND source_key=?', [$run['id'], $prefix . 'credit', $movement['source_key']]);
            if ($attempt && in_array($attempt['status'], ['archived', 'skipped'], true)) {
                $column = $attempt['status'];
                $this->store->execute("UPDATE whmcstofossbilling_cursor SET `$column`=`$column`-1,imported=imported+1 WHERE run_id=? AND table_name=?", [$run['id'], $prefix . 'credit']);
                $this->store->execute("UPDATE whmcstofossbilling_attempt SET status='imported',updated_at=UTC_TIMESTAMP() WHERE run_id=? AND table_name=? AND source_key=?", [$run['id'], $prefix . 'credit', $movement['source_key']]);
            }
        }
        $actual = $this->store->one('SELECT CAST(COALESCE(SUM(amount),0) AS DECIMAL(18,2)) AS amount FROM client_balance WHERE client_id=?', [$clientId]);
        if ($actual['amount'] !== $balance) throw new SafeException('Native closing balance differs from the source. Entire client credit aggregate rolled back.');
    }
}
