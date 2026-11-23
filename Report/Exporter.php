<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Report;

use Box\Mod\Whmcstofossbilling\Destination\Store;
use Box\Mod\Whmcstofossbilling\Service;
use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class Exporter
{
    public function __construct(private readonly Service $service)
    {
    }

    public static function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/\A[\s]*[=+@\-\t\r]/', $value) ? "'" . $value : $value;
    }

    public function response(string $runId, string $format, bool $exceptionsOnly = false): StreamedResponse
    {
        if (!in_array($format, ['json', 'csv'], true)) {
            throw new SafeException('Reports support JSON and CSV only.');
        }
        $run = $this->service->status($runId);
        $store = $this->service->store();
        // The status view intentionally caps its UI list; the full report must not.
        // Exception-only exports remain failed-record-only, so successful inference
        // disclosures are omitted there while their total remains available.
        $currencyInferenceTotal = (int) $store->one("SELECT COUNT(*) AS n FROM whmcstofossbilling_attempt WHERE run_id=? AND status='currency_inference'", [$runId])['n'];
        $currencyInferences = $exceptionsOnly ? [] : $store->all("SELECT table_name,source_key,message AS disclosure FROM whmcstofossbilling_attempt WHERE run_id=? AND status='currency_inference' ORDER BY id", [$runId]);
        // No payloads, names, email addresses, credentials, notes or backup references in reports.
        $summary = [
            'run_id' => $runId, 'mode' => $run['mode'], 'status' => $run['status'],
            'source_id' => $run['source_id'], 'created_at' => $run['created_at'],
            'updated_at' => $run['updated_at'], 'categories' => $run['categories'],
            'limitations' => $run['plan']['blockers'], 'reconciliation' => $run['reconciliation'],
            'count_scope' => 'Counts were captured per table, not from a cross-request consistent snapshot.',
            'preview' => $run['mode'] === 'dry_run',
            'scope' => $exceptionsOnly ? 'failed_records_only' : 'all_records',
            'missing_invoice_currency_policy' => $run['plan']['missing_invoice_currency'] ?? null,
            'currency_inference_total' => $currencyInferenceTotal,
            'currency_inferences' => $currencyInferences,
            'product_pricing' => $run['product_pricing'], 'credit_publication' => $run['credit_publication'],
        ];
        $fullRun = $store->run($runId);
        $schema = json_decode($fullRun['schema_json'], true, 128, JSON_THROW_ON_ERROR);
        $mapping = json_decode($fullRun['mapping_json'], true, 128, JSON_THROW_ON_ERROR);
        $summary['table_classifications'] = [];
        $summary['field_dispositions'] = [];
        foreach ($schema as $name => $definition) {
            $summary['table_classifications'][$name] = $definition['classification'];
            foreach ($mapping['fields'][$name] as $column => $rule) {
                $summary['field_dispositions'][] = ['table' => $name, 'field' => $column, 'action' => $rule['action'], 'target' => $rule['target'], 'transform' => $rule['transform']];
            }
        }
        $attemptQuery = 'SELECT id,table_name,source_key,status,error_type,message,attempts FROM whmcstofossbilling_attempt WHERE run_id=? AND id>?'
            . ($exceptionsOnly ? " AND status='failed'" : '')
            . ' ORDER BY id LIMIT 500';
        $response = new StreamedResponse(static function () use ($summary, $store, $runId, $format, $attemptQuery): void {
            $output = fopen('php://output', 'wb');
            if ($format === 'json') {
                fwrite($output, '{"summary":' . Store::json($summary) . ',"records":[');
            } else {
                fputcsv($output, ['kind', 'table', 'source_id', 'destination_entity', 'destination_id', 'status', 'expected', 'processed', 'imported', 'archived', 'skipped', 'failed', 'metric', 'currency', 'source_amount', 'native_amount', 'source_records', 'native_records'], ',', '"', '');
                foreach ($summary['categories'] as $category) {
                    fputcsv($output, array_map(self::csvCell(...), ['category', $category['table_name'], '', '', '', $summary['preview'] ? 'preview_only' : $summary['status'], $category['expected'], $category['processed'], $category['imported'], $summary['preview'] ? 0 : $category['archived'], $category['skipped'], $category['failed'], '', '', '', '', '', '']), ',', '"', '');
                }
                foreach ($summary['reconciliation']['totals_by_currency'] ?? [] as $metric => $totals) {
                    foreach ($totals as $total) {
                        fputcsv($output, array_map(self::csvCell(...), ['financial_snapshot', '', '', '', '', $summary['reconciliation']['status'], '', '', '', '', '', '', $metric, $total['currency'], $total['source_total'], $total['native_total'], $total['source_records'], $total['native_records']]), ',', '"', '');
                    }
                }
                foreach ($summary['currency_inferences'] ?? [] as $inference) {
                    fputcsv($output, array_map(self::csvCell(...), ['currency_inference', $inference['table_name'], $inference['source_key'], '', '', $summary['status'], '', '', '', '', '', '', $inference['disclosure'], '', '', '', '', '']), ',', '"', '');
                }
            }
            $after = 0;
            $first = true;
            do {
                // Page attempts, not a one-to-many identity join: an aggregate can map
                // to multiple destination records without losing children at a page boundary.
                $records = $store->all($attemptQuery, [$runId, $after]);
                foreach ($records as $row) {
                    $after = (int) $row['id'];
                    unset($row['id']);
                    $destinations = $store->all('SELECT destination_entity,destination_id FROM whmcstofossbilling_identity WHERE source_id=? AND table_name=? AND source_key=? ORDER BY destination_entity', [$summary['source_id'], $row['table_name'], $row['source_key']]);
                    $row['destinations'] = $destinations;
                    if ($format === 'json') {
                        fwrite($output, ($first ? '' : ',') . Store::json($row));
                    } else {
                        foreach ($destinations ?: [['destination_entity' => '', 'destination_id' => '']] as $destination) {
                            fputcsv($output, array_map(self::csvCell(...), ['record', $row['table_name'], $row['source_key'], $destination['destination_entity'], $destination['destination_id'], $row['status'], '', '', '', '', '', '', '', '', '', '', '', '']), ',', '"', '');
                        }
                    }
                    $first = false;
                }
            } while (count($records) === 500);
            if ($format === 'json') {
                fwrite($output, ']}');
            }
            fclose($output);
        });
        $response->headers->set('Content-Type', $format === 'json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="whmcstofossbilling-' . $runId . '.' . $format . '"');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
