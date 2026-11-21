<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Migration;

use Box\Mod\Whmcstofossbilling\Destination\NativeWriter;
use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Source\Catalog;
use Box\Mod\Whmcstofossbilling\Destination\Store;

final class Planner
{
    public const BLOCKERS = [
        'Only canceled/terminated/fraud hosting history has a reviewed native path. Active/suspended/pending orders and every domain order remain blocked pending automation isolation.',
        'Unpaid/draft invoices remain blocked because core expiry cleanup ignores approval. Unsupported compound taxes, ambiguous currencies, allocations and oversized aggregates remain archived/failed.',
        'Native product pricing uses only the actual destination base currency. Independent currency matrices are preserved separately; configurable options and opaque module package slots are not activated.',
        'Only closed tickets are published natively. Contacts, unrepresentable custom fields, structured notes and audit records remain private archives.',
        'Credit aggregates publish only when complete movements exactly equal the source closing balance. No opening adjustments are synthesized. Outstanding debt/allocation reconstruction and live cutover reconciliation remain unverified.',
        'Database-only access cannot transfer attachment files. Attachment references are not imported attachments.',
        'Unknown/custom tables and tables without a single integer primary key require a reviewed extractor.',
        'A live source is not a consistent multi-request snapshot. Final cutover reconciliation is required.',
    ];

    public static function analysisIssues(Store $store, int $source, array $schema, array $selection): array
    {
        if ($selection === []) {
            throw new SafeException('Select at least one source table.');
        }
        $unknown = array_diff($selection, array_keys($schema));
        if ($unknown) {
            throw new SafeException('The selection contains an unknown table.');
        }
        $issues = [];
        $checksum = hash('sha256', Store::json($schema));
        foreach ($selection as $name) {
            $definition = $schema[$name];
            if ($definition['classification'] !== 'archive') {
                throw new SafeException('A selected table is sensitive, unsupported, or requires a reviewed extractor.');
            }
            $analysis = $store->one('SELECT *, analyzed_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR AS fresh FROM whmcstofossbilling_analysis WHERE source_id=? AND table_name=?', [$source, $name]);
            if (!$analysis) $issues[$name] = 'missing';
            elseif ($analysis['schema_checksum'] !== $checksum) $issues[$name] = 'schema changed';
            elseif (!(int) $analysis['fresh']) $issues[$name] = 'stale';
        }

        return $issues;
    }

    public static function build(Store $store, int $source, array $schema, array $selection): array
    {
        $issues = self::analysisIssues($store, $source, $schema, $selection);
        if ($issues) {
            $names = [];
            foreach ($issues as $name => $reason) $names[] = $name . ' (' . $reason . ')';
            throw new SafeException('Source counts need refreshing: ' . implode(', ', $names) . '. Analyze these tables again. If the schema changed, test the connection first.');
        }
        $tables = [];
        foreach ($selection as $name) {
            $definition = $schema[$name];
            // Recheck at use time as well: preparation never replaces the hard freshness gate.
            $analysis = $store->one('SELECT * FROM whmcstofossbilling_analysis WHERE source_id=? AND table_name=? AND analyzed_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [$source, $name]);
            if (!$analysis || $analysis['schema_checksum'] !== hash('sha256', Store::json($schema))) {
                throw new SafeException('Source counts need refreshing: ' . $name . '. Analyze this table again; if the schema changed, test the connection first.');
            }
            $tables[$name] = [
                'category' => Catalog::category($definition['suffix']),
                'native' => NativeWriter::ENTITIES[$definition['suffix']] ?? null,
                'count' => (int) $analysis['expected'], 'maximum' => $analysis['maximum'],
            ];
        }
        $order = ['currencies', 'clientgroups', 'clients', 'servers', 'servergroups', 'servergroupsrel', 'productgroups', 'products', 'addons', 'pricing', 'hosting', 'invoiceitems', 'invoices', 'accounts', 'credit', 'ticketdepartments', 'tickets', 'ticketreplies', 'ticketnotes', 'emails'];
        uksort($tables, static function (string $a, string $b) use ($schema, $order): int {
            $ai = array_search($schema[$a]['suffix'], $order, true);
            $bi = array_search($schema[$b]['suffix'], $order, true);

            return ($ai === false ? 100 : $ai) <=> ($bi === false ? 100 : $bi) ?: strcmp($a, $b);
        });

        return ['tables' => $tables, 'blockers' => self::BLOCKERS, 'consistent_snapshot' => false, 'source_count' => array_sum(array_column($tables, 'count'))];
    }
}
