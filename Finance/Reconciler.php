<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Finance;

use Box\Mod\Whmcstofossbilling\Destination\Store;
use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Security\Sensitive;

/**
 * Compares the extracted baseline with actual native records, in bounded requests.
 * An archive is not counted as a native financial record. No adjustments are posted.
 */
final class Reconciler
{
    public function __construct(private readonly Store $store)
    {
    }

    public function batch(array $run, int $limit = 50): bool
    {
        $plan = json_decode($run['plan_json'], true, 128, JSON_THROW_ON_ERROR);
        $prefix = $plan['source_prefix'] ?? null;
        if (!$prefix || $run['mode'] === 'dry_run') return true;
        $this->store->execute('INSERT IGNORE INTO whmcstofossbilling_reconcile_state (run_id) VALUES (?)', [$run['id']]);
        $state = $this->store->one('SELECT * FROM whmcstofossbilling_reconcile_state WHERE run_id=?', [$run['id']]);
        if ($state['status'] === 'finished') return true;
        $rows = $this->store->all('SELECT id,source_id,table_name,source_key,payload FROM whmcstofossbilling_snapshot WHERE run_id=? AND id>? AND table_name IN (?,?,?,?) ORDER BY id LIMIT ' . min(50, max(1, $limit)), [$run['id'], $state['after_id'], $prefix . 'clients', $prefix . 'invoices', $prefix . 'accounts', $prefix . 'credit']);
        foreach ($rows as $record) {
            $this->store->pdo->beginTransaction();
            try {
                $row = json_decode($record['payload'], true, 128, JSON_THROW_ON_ERROR);
                $suffix = substr($record['table_name'], strlen($prefix));
                [$code, $basis] = $this->currency($run['id'], $prefix, $suffix, $row);
                $source = $this->sourceMetrics($suffix, $row);
                $native = $this->nativeMetrics((int) $record['source_id'], $record['table_name'], $record['source_key'], $suffix);
                $nativeCurrency = $native['_currency'] ?? null;
                $modified = $native['_modified'] ?? false;
                if ($native !== null) unset($native['_currency'], $native['_modified']);
                $status = 'matched';
                if ($code === null) $status = 'unresolved_currency';
                elseif ($native === null) $status = 'missing_native';
                elseif ($nativeCurrency !== $code) $status = 'currency_conflict';
                elseif ($modified) $status = 'destination_modified';
                elseif ($source !== $native) $status = 'discrepancy';
                elseif ($basis !== 'explicit_source_currency') $status = 'requires_review';
                if ($status === 'currency_conflict') {
                    $native['_currency_conflict'] = true;
                    $native['_actual_currency'] = $nativeCurrency;
                }
                $this->save($run['id'], $record, $suffix, $code, $basis, $source, $native ?? [], $status, null);
                $this->store->execute('UPDATE whmcstofossbilling_reconcile_state SET after_id=?,checked_at=UTC_TIMESTAMP() WHERE run_id=?', [$record['id'], $run['id']]);
                $this->store->pdo->commit();
            } catch (\Throwable $error) {
                if ($this->store->pdo->inTransaction()) $this->store->pdo->rollBack();
                $this->store->pdo->beginTransaction();
                try {
                    $this->save($run['id'], $record, substr($record['table_name'], strlen($prefix)), null, 'unresolved', [], [], 'invalid_source_or_native', Sensitive::error($error));
                    $this->store->execute('UPDATE whmcstofossbilling_reconcile_state SET after_id=?,checked_at=UTC_TIMESTAMP() WHERE run_id=?', [$record['id'], $run['id']]);
                    $this->store->pdo->commit();
                } catch (\Throwable $persistError) {
                    $this->store->pdo->rollBack();
                    throw $persistError;
                }
            }
        }
        if (count($rows) < min(50, max(1, $limit))) {
            $this->store->execute("UPDATE whmcstofossbilling_reconcile_state SET status='finished',checked_at=UTC_TIMESTAMP() WHERE run_id=?", [$run['id']]);
            return true;
        }

        return false;
    }

    private function sourceMetrics(string $suffix, array $row): array
    {
        return match ($suffix) {
            'clients' => ['balance' => Decimal::money($row['credit'] ?? throw new SafeException('Source final credit balance is unavailable.'))],
            'credit' => ['credit_movement' => Decimal::money($row['amount'] ?? '')],
            'accounts' => [
                'received' => Decimal::money($row['amountin'] ?? ''),
                'refunded' => Decimal::money($row['amountout'] ?? ''),
                // No native fee column exists; it remains a disclosed unresolved native metric.
                'fees' => Decimal::money($row['fees'] ?? '0'),
            ],
            'invoices' => [
                'invoice_value' => Decimal::money($row['total'] ?? ''),
                'invoice_credit' => Decimal::money($row['credit'] ?? '0'),
                'paid_invoice_value' => strtolower((string) ($row['status'] ?? '')) === 'paid' ? Decimal::money($row['total']) : '0.00',
                'unpaid_invoice_value' => strtolower((string) ($row['status'] ?? '')) === 'unpaid' ? Decimal::money($row['total']) : '0.00',
            ],
            default => throw new SafeException('Unsupported financial assessment category.'),
        };
    }

    private function nativeMetrics(int $source, string $table, string $key, string $suffix): ?array
    {
        $entity = match ($suffix) { 'clients' => 'client', 'credit' => 'client_balance', 'accounts' => 'transaction', 'invoices' => 'invoice' };
        $mapping = $this->store->one('SELECT destination_id,destination_checksum FROM whmcstofossbilling_identity WHERE source_id=? AND table_name=? AND source_key=? AND destination_entity=?', [$source, $table, $key, $entity]);
        if (!$mapping) return null;
        $id = $mapping['destination_id'];
        $current = $this->store->one('SELECT * FROM `' . $entity . '` WHERE id=?', [$id]);
        if (!$current) return null;
        $modified = !hash_equals((string) $mapping['destination_checksum'], hash('sha256', Store::json($current)));
        if ($suffix === 'clients') {
            $sum = $this->store->one('SELECT CAST(COALESCE(SUM(amount),0) AS DECIMAL(18,2)) AS amount FROM client_balance WHERE client_id=?', [$id]);
            if ($this->store->one("SELECT b.id FROM client_balance b LEFT JOIN whmcstofossbilling_identity i ON i.source_id=? AND i.destination_entity='client_balance' AND i.destination_id=b.id WHERE b.client_id=? AND i.id IS NULL LIMIT 1", [$source, $id])) $modified = true;

            return ['balance' => Decimal::money($sum['amount']), '_currency' => $current['currency'], '_modified' => $modified];
        }
        if ($suffix === 'credit') {
            $row = $this->store->one('SELECT amount FROM client_balance WHERE id=?', [$id]);
            $client = $this->store->one('SELECT currency FROM client WHERE id=?', [$current['client_id']]);
            return $row ? ['credit_movement' => Decimal::money($row['amount']), '_currency' => $client['currency'] ?? null, '_modified' => $modified] : null;
        }
        if ($suffix === 'accounts') {
            $row = $this->store->one('SELECT amount,type,status FROM transaction WHERE id=?', [$id]);
            if (!$row || $row['status'] !== 'processed') return null;
            return [
                'received' => $row['type'] === 'payment' ? Decimal::money($row['amount']) : '0.00',
                'refunded' => $row['type'] === 'refund' ? Decimal::money($row['amount']) : '0.00',
                'fees' => null,
                '_currency' => $current['currency'], '_modified' => $modified,
            ];
        }
        $invoice = $this->store->one('SELECT status,taxrate,CAST(credit AS DECIMAL(18,2)) AS credit FROM invoice WHERE id=?', [$id]);
        if (!$invoice) return null;
        $amount = $this->store->one('SELECT CAST(COALESCE(SUM(CAST(price AS DECIMAL(18,2))*quantity),0) AS DECIMAL(18,2)) AS subtotal,CAST(COALESCE(SUM(CASE WHEN taxed=1 THEN CAST(price AS DECIMAL(18,2))*quantity ELSE 0 END),0) AS DECIMAL(18,2)) AS taxable FROM invoice_item WHERE invoice_id=?', [$id]);
        $total = bcadd($amount['subtotal'], Decimal::tax($amount['taxable'], Decimal::money($invoice['taxrate'] ?? '0', 6)), 2);

        $children = $this->store->all("SELECT ii.*,i.destination_checksum AS expected_hash FROM invoice_item ii LEFT JOIN whmcstofossbilling_identity i ON i.source_id=? AND i.destination_entity='invoice_item' AND i.destination_id=ii.id WHERE ii.invoice_id=? LIMIT 201", [$source, $id]);
        if (count($children) > 200) $modified = true;
        foreach ($children as $child) {
            $expected = $child['expected_hash'];
            unset($child['expected_hash']);
            if ($expected === null || !hash_equals($expected, hash('sha256', Store::json($child)))) $modified = true;
        }

        return ['invoice_value' => $total, 'invoice_credit' => Decimal::money($invoice['credit'] ?? '0'), 'paid_invoice_value' => $invoice['status'] === 'paid' ? $total : '0.00', 'unpaid_invoice_value' => $invoice['status'] === 'unpaid' ? $total : '0.00', '_currency' => $current['currency'], '_modified' => $modified];
    }

    private function currency(string $run, string $prefix, string $suffix, array $row): array
    {
        $value = $row['currency'] ?? null;
        $basis = 'explicit_source_currency';
        if ($value === null && $suffix !== 'clients') {
            $clientKey = (string) ($row['userid'] ?? $row['clientid'] ?? '');
            $client = $this->store->one('SELECT payload FROM whmcstofossbilling_snapshot WHERE run_id=? AND table_name=? AND source_key=?', [$run, $prefix . 'clients', $clientKey]);
            $value = $client ? (json_decode($client['payload'], true, 128, JSON_THROW_ON_ERROR)['currency'] ?? null) : null;
            $basis = 'inferred_current_client_currency';
        }
        if (is_string($value) && preg_match('/\A[A-Za-z]{3}\z/D', $value)) return [strtoupper($value), $basis];
        $currency = $this->store->one('SELECT payload FROM whmcstofossbilling_snapshot WHERE run_id=? AND table_name=? AND source_key=?', [$run, $prefix . 'currencies', (string) $value]);
        $code = $currency ? (json_decode($currency['payload'], true, 128, JSON_THROW_ON_ERROR)['code'] ?? null) : null;

        return [is_string($code) && preg_match('/\A[A-Za-z]{3}\z/D', $code) ? strtoupper($code) : null, $basis];
    }

    private function save(string $run, array $record, string $category, ?string $code, string $basis, array $source, array $native, string $status, ?string $message): void
    {
        $previous = $this->store->one('SELECT currency,source_metrics,native_metrics FROM whmcstofossbilling_assessment WHERE run_id=? AND table_name=? AND source_key=?', [$run, $record['table_name'], $record['source_key']]);
        if ($previous) {
            $this->totals($run, $previous['currency'], json_decode($previous['source_metrics'], true, 32, JSON_THROW_ON_ERROR), json_decode($previous['native_metrics'], true, 32, JSON_THROW_ON_ERROR), -1);
        }
        $this->store->execute('INSERT INTO whmcstofossbilling_assessment (run_id,table_name,source_key,category,currency,currency_basis,source_metrics,native_metrics,status,message,checked_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE source_metrics=VALUES(source_metrics),native_metrics=VALUES(native_metrics),status=VALUES(status),message=VALUES(message),checked_at=UTC_TIMESTAMP()', [$run, $record['table_name'], $record['source_key'], $category, $code, $basis, Store::json($source), Store::json($native), $status, $message]);
        $this->totals($run, $code, $source, $native, 1);
    }

    private function totals(string $run, ?string $code, array $source, array $native, int $direction): void
    {
        if ($code === null) return;
        foreach ($source as $metric => $value) {
            $actual = empty($native['_currency_conflict']) && is_string($native[$metric] ?? null) ? $native[$metric] : null;
            $this->store->execute('INSERT INTO whmcstofossbilling_reconcile_total (run_id,metric,currency,source_total,native_total,source_records,native_records) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_total=source_total+VALUES(source_total),native_total=native_total+VALUES(native_total),source_records=source_records+VALUES(source_records),native_records=native_records+VALUES(native_records)', [
                $run, $metric, $code, bcmul($value, (string) $direction, 2),
                $actual === null ? '0.00' : bcmul($actual, (string) $direction, 2),
                $direction, $actual === null ? 0 : $direction,
            ]);
        }
    }

    public function summary(string $run): array
    {
        $state = $this->store->one('SELECT status,checked_at FROM whmcstofossbilling_reconcile_state WHERE run_id=?', [$run]);
        if (!$state) return ['status' => 'not_performed', 'reason' => 'No completed extracted-baseline assessment exists for this run.'];
        $counts = $this->store->all('SELECT status,COUNT(*) AS records FROM whmcstofossbilling_assessment WHERE run_id=? GROUP BY status', [$run]);
        $exceptions = $this->store->all("SELECT table_name,source_key,currency,currency_basis,status,message,source_metrics,native_metrics FROM whmcstofossbilling_assessment WHERE run_id=? AND status<>'matched' ORDER BY id LIMIT 100", [$run]);
        foreach ($exceptions as &$exception) {
            $exception['source_metrics'] = json_decode($exception['source_metrics'], true, 32, JSON_THROW_ON_ERROR);
            $exception['native_metrics'] = json_decode($exception['native_metrics'], true, 32, JSON_THROW_ON_ERROR);
        }
        $totals = [];
        // Totals are updated atomically with each assessment/checkpoint, so dashboard
        // polling never rescans all JSON records or mixes currencies.
        foreach ($this->store->all('SELECT metric,currency,CAST(source_total AS CHAR) AS source_total,CASE WHEN native_records=0 THEN NULL ELSE CAST(native_total AS CHAR) END AS native_total,source_records,native_records FROM whmcstofossbilling_reconcile_total WHERE run_id=? AND source_records>0 ORDER BY metric,currency', [$run]) as $total) {
            $metric = $total['metric'];
            unset($total['metric']);
            $totals[$metric][] = $total;
        }
        $bad = array_sum(array_map(static fn (array $row): int => $row['status'] === 'matched' ? 0 : (int) $row['records'], $counts));

        return [
            'status' => $state['status'] !== 'finished' ? 'in_progress' : ($bad ? 'exceptions' : 'matched_extracted_subset'),
            'reason' => 'Extracted baseline versus native database at the recorded check time. Missing native records, fees without a native column, inferred currencies and differences remain explicit exceptions. This is not live-source/cutover reconciliation or a complete migration.',
            'checked_at' => $state['checked_at'], 'counts' => $counts, 'totals_by_currency' => $totals, 'exceptions' => $exceptions,
            'outstanding_balance_status' => 'not_verified: complete allocation/credit/refund reconstruction is required',
        ];
    }
}
