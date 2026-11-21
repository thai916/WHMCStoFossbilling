<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Archive;

use Box\Mod\Whmcstofossbilling\Destination\Store;
use Box\Mod\Whmcstofossbilling\Security\SafeException;

final class Repository
{
    public function __construct(private readonly Store $store)
    {
    }

    public function preserve(int $source, string $table, string $key, array $row, array $definition, array $rules, string $run): array
    {
        if (!empty($row['__wm_missing'])) throw new SafeException('Missing source contents cannot become an archive record.');
        if (!empty($row['__wm_oversized'])) throw new SafeException('Oversized source contents were not extracted. Null substitutes must never become an archive record.');
        $data = $dates = $links = $exclusions = [];
        foreach ($definition['columns'] as $column => $field) {
            $rule = $rules[$column];
            if ($field['sensitive'] || $rule['action'] === 'exclude_sensitive') {
                $exclusions[$column] = 'Sensitive field excluded without extraction';
                continue;
            }
            if ($rule['action'] === 'skip') {
                $exclusions[$column] = 'Administrator-approved skip: ' . $rule['reason'];
                continue;
            }
            $value = $row[$column] ?? null;
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                throw new SafeException('A source value contains binary or invalid UTF-8 data. A reviewed binary extractor is required; no bytes were silently replaced.');
            }
            if (is_string($value) && strlen($value) > 2 * 1024 * 1024) {
                throw new SafeException('A source field exceeds the safe row size. A separately reviewed large-object extractor is required; it was not truncated.');
            }
            // Numeric financial/date/relationship strings are exact; sanitize only narrative text.
            if (is_string($value) && preg_match('/notes?|message|content|description|body|config|value|details|text|data|payload/i', $column) && !is_numeric($value)) {
                $safe = Sanitizer::text($value);
                if ($safe !== $value) {
                    $exclusions[$column] = 'Potential secret in free text redacted; original value not retained';
                }
                $value = $safe;
            }
            $data[$column] = $value;
            if (preg_match('/date|time|created|updated|lastlogin/i', $column)) {
                $dates[$column] = $value;
            }
            if (preg_match('/(?:id|userid|clientid|relid|invoiceid|serviceid|domainid)\z/i', $column) && $column !== $definition['key']) {
                $links[$column] = $value;
            }
        }
        $payload = Store::json($data);
        if (strlen($payload) > 4 * 1024 * 1024) {
            throw new SafeException('A source record exceeds the archive row limit. It was not truncated.');
        }
        $checksum = hash('sha256', $payload);
        $previous = $this->store->one('SELECT * FROM whmcstofossbilling_archive WHERE source_id=? AND table_name=? AND source_key=?', [$source, $table, $key]);
        $changed = !$previous || $previous['checksum'] !== $checksum || $previous['exclusions'] !== Store::json($exclusions);
        if ($previous && $changed) {
            $this->store->execute('INSERT INTO whmcstofossbilling_archive_revision (archive_id,payload,checksum,exclusions,run_id,preserved_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())', [$previous['id'], $previous['payload'], $previous['checksum'], $previous['exclusions'], $previous['run_id']]);
        }
        $this->store->execute(
            'INSERT INTO whmcstofossbilling_archive (source_id,table_name,source_key,payload,checksum,original_dates,relationships,exclusions,run_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE payload=VALUES(payload),checksum=VALUES(checksum),original_dates=VALUES(original_dates),relationships=VALUES(relationships),exclusions=VALUES(exclusions),run_id=VALUES(run_id),updated_at=UTC_TIMESTAMP()',
            [$source, $table, $key, $payload, $checksum, Store::json($dates), Store::json($links), Store::json($exclusions), $run]
        );
        $this->store->execute('DELETE FROM whmcstofossbilling_archive_link WHERE source_id=? AND table_name=? AND source_key=?', [$source, $table, $key]);
        foreach ($links as $field => $value) {
            if ((is_string($value) || is_int($value)) && strlen((string) $value) <= 191) {
                $this->store->execute('INSERT INTO whmcstofossbilling_archive_link (source_id,table_name,source_key,field_name,target_key) VALUES (?,?,?,?,?)', [$source, $table, $key, $field, (string) $value]);
            }
        }
        // A new run must not replace the evidence used by a previous run's
        // projections/reconciliation. Latest searchable archive and per-run snapshot are distinct.
        $this->store->execute('INSERT INTO whmcstofossbilling_snapshot (run_id,source_id,table_name,source_key,payload,checksum) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),checksum=VALUES(checksum)', [$run, $source, $table, $key, $payload, $checksum]);
        $snapshot = $this->store->one('SELECT id FROM whmcstofossbilling_snapshot WHERE run_id=? AND table_name=? AND source_key=?', [$run, $table, $key]);
        $this->store->execute('DELETE FROM whmcstofossbilling_snapshot_link WHERE snapshot_id=?', [$snapshot['id']]);
        foreach ($links as $field => $value) {
            if ((is_string($value) || is_int($value)) && strlen((string) $value) <= 191) {
                $this->store->execute('INSERT INTO whmcstofossbilling_snapshot_link (snapshot_id,run_id,table_name,field_name,target_key) VALUES (?,?,?,?,?)', [$snapshot['id'], $run, $table, $field, (string) $value]);
            }
        }
        $referenceField = match ($definition['suffix']) { 'invoices' => 'invoicenum', 'tickets' => 'tid', default => null };
        if ($referenceField !== null && isset($data[$referenceField])) {
            $kind = $definition['suffix'] === 'invoices' ? 'invoice' : 'ticket';
            $this->store->execute('INSERT INTO whmcstofossbilling_legacy_reference (source_id,table_name,source_key,kind,reference_hash) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE reference_hash=VALUES(reference_hash)', [$source, $table, $key, $kind, hash('sha256', trim((string) $data[$referenceField]))]);
        }

        return ['checksum' => $checksum, 'changed' => $changed];
    }
}
