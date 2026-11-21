<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Destination;

use Box\Mod\Whmcsmigrator\Archive\Sanitizer;
use Box\Mod\Whmcsmigrator\Finance\Decimal;
use Box\Mod\Whmcsmigrator\Mapping\Profile;
use Box\Mod\Whmcsmigrator\Security\SafeException;

/**
 * Historical persistence deliberately bypasses eventful lifecycle APIs.
 * Dispatches only reviewed adapters. Run plans snapshot the authorized entity set.
 * Unsafe operational/financial cases fail closed and retain their source archive.
 */
final class NativeWriter
{
    public const DATE_FALLBACK = '1900-01-01 00:00:00';

    public const ENTITIES = [
        'currencies' => 'currency', 'clientgroups' => 'client_group', 'clients' => 'client',
        'productgroups' => 'product_category', 'products' => 'product',
        'addons' => 'product', 'pricing' => 'product_payment', 'servers' => 'service_hosting_server',
        ...HistoryImporter::ENTITIES,
        'invoices' => 'invoice', 'accounts' => 'transaction',
        'hosting' => 'client_order',
    ];

    private ?string $dateFallbackOriginal = null;

    public function __construct(private readonly Store $store, private readonly int $source, private readonly string $prefix, private readonly ?string $timezone = null, private readonly ?string $invalidDateFallback = null)
    {
    }

    private function registrationDate(mixed $value): ?string
    {
        $this->dateFallbackOriginal = null;
        $used = false;
        $date = \Box\Mod\Whmcsmigrator\Mapping\EventDate::utc($value, $this->timezone, $this->invalidDateFallback, $used);
        if ($used && is_string($value)) $this->dateFallbackOriginal = $value;

        return $date;
    }

    public function dateFallbackOriginal(): ?string
    {
        return $this->dateFallbackOriginal;
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format($format) !== $value) {
            throw new SafeException('A historical date is invalid. The original value remains in the archive.');
        }

        return $date->format('Y-m-d H:i:s');
    }

    public function import(string $suffix, array $row, array $rules, string $checksum, string $run): string
    {
        $this->dateFallbackOriginal = null;
        if (isset(HistoryImporter::ENTITIES[$suffix])) {
            return (new HistoryImporter($this->store, $this->source, $this->prefix, $this->timezone))->import($suffix, $row, $checksum, $run);
        }
        if ($suffix === 'invoices') return (new InvoiceImporter($this->store, $this->source, $this->prefix, $this->timezone))->import($row, $checksum, $run);
        if ($suffix === 'accounts') return (new PaymentImporter($this->store, $this->source, $this->prefix, $this->timezone))->import($row, $checksum, $run);
        if ($suffix === 'hosting') return (new HostingImporter($this->store, $this->source, $this->prefix, $this->timezone))->import($row, $checksum, $run);
        if (in_array($suffix, ['products', 'addons'], true)) {
            return (new ProductImporter($this->store, $this->source, $this->prefix))->import($suffix, $row, $checksum, $run);
        }
        if ($suffix === 'pricing') {
            return (new ProductPricing($this->store, $this->source, $this->prefix))->import($row, $checksum, $run);
        }
        if ($suffix === 'servers') {
            return (new ServerImporter($this->store, $this->source, $this->prefix))->import($row, $checksum, $run);
        }
        if (!isset(self::ENTITIES[$suffix])) {
            return 'archived';
        }
        $entity = self::ENTITIES[$suffix];
        $key = (string) $row['id'];
        $previous = $this->store->one('SELECT * FROM whmcsmigrator_identity WHERE source_id=? AND table_name=? AND source_key=? AND destination_entity=?', [$this->source, $this->prefix . $suffix, $key, $entity]);
        if ($previous !== null) {
            if ($previous['checksum'] !== $checksum) {
                throw new SafeException('Source record changed after its native import. Destination preserved; a reviewed field-update workflow is required.');
            }
            $current = $this->store->one('SELECT * FROM `' . $entity . '` WHERE id=?', [$previous['destination_id']]);
            if (!$current || hash('sha256', Store::json($current)) !== $previous['destination_checksum']) {
                throw new SafeException('A linked destination record changed or was deleted. Automatic overwrite refused.');
            }

            return 'skipped';
        }
        $data = match ($suffix) {
            'currencies' => $this->currency($row),
            'clientgroups' => ['title' => $row['groupname'] ?? '', 'created_at' => null, 'updated_at' => null],
            'clients' => $this->client($row, $rules),
            'productgroups' => ['title' => $row['name'] ?? '', 'description' => Sanitizer::text((string) ($row['headline'] ?? $row['tagline'] ?? '')), 'priority' => $row['order'] ?? 0, 'created_at' => null, 'updated_at' => null],
        };
        $linked = $suffix === 'currencies' ? $this->store->one('SELECT * FROM currency WHERE code=?', [$data['code']]) : null;
        if ($linked) {
            // Currency code is an ISO identity, not a customer matching heuristic. Preserve existing rates/default.
            $id = (int) $linked['id'];
        } else {
            $id = $this->insert($entity, $data);
        }
        $current = $this->store->one('SELECT * FROM `' . $entity . '` WHERE id=?', [$id]);
        $this->store->execute('INSERT INTO whmcsmigrator_identity (source_id,table_name,source_key,destination_entity,destination_id,checksum,destination_checksum,run_id,created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())', [$this->source, $this->prefix . $suffix, $key, $entity, $id, $checksum, hash('sha256', Store::json($current)), $run]);
        $status = $linked ? 'skipped' : 'imported';

        return $status === 'imported' && $this->dateFallbackOriginal !== null ? 'date_fallback' : $status;
    }

    public function validateClientPreview(array $row, array $rules): void
    {
        $email = null;
        foreach ($rules as $field => $rule) {
            if ($rule['action'] === 'native_and_archive' && $rule['target'] === 'email') {
                $email = Profile::transform($row[$field] ?? null, $rule['transform']);
            }
        }
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SafeException('Client email mapping is missing or invalid.');
        }
        if ($this->store->one('SELECT id FROM client WHERE email=?', [mb_strtolower(trim($email))])) {
            $map = $this->store->one('SELECT id FROM whmcsmigrator_identity WHERE source_id=? AND table_name=? AND source_key=? AND destination_entity=?', [$this->source, $this->prefix . 'clients', (string) $row['id'], 'client']);
            if (!$map) {
                throw new SafeException('Client email conflicts with an existing destination account. No automatic link or merge is allowed.');
            }
        }
        $this->registrationDate($row['datecreated'] ?? null);
    }

    private function currency(array $row): array
    {
        $code = strtoupper((string) ($row['code'] ?? ''));
        if (!preg_match('/\A[A-Z]{3}\z/D', $code)) {
            throw new SafeException('Currency does not have a valid three-letter code.');
        }
        if (!\Symfony\Component\Intl\Currencies::exists($code)) throw new SafeException('Currency cannot be rendered by the installed native currency API. Original currency data remains archived.');

        $rate = Decimal::money((string) ($row['rate'] ?? '1'), 6);
        if (bccomp($rate, '0', 6) <= 0) {
            throw new SafeException('A currency conversion rate must be positive.');
        }

        return ['code' => $code, 'conversion_rate' => $rate, 'is_default' => 0, 'is_rate_manual' => 1];
    }

    private function client(array $row, array $rules): array
    {
        $this->validateClientPreview($row, $rules);
        $data = [];
        foreach ($rules as $column => $rule) {
            if ($rule['action'] === 'native_and_archive') {
                $value = Profile::transform($row[$column] ?? null, $rule['transform']);
                $data[$rule['target']] = $rule['target'] === 'notes' && is_string($value) ? Sanitizer::text($value) : $value;
            }
        }
        $data['email'] = mb_strtolower(trim($data['email']));
        if (!empty($data['country']) && !\Symfony\Component\Intl\Countries::exists($data['country'])) {
            throw new SafeException('Client country is not a recognized ISO country code.');
        }
        $currencyId = $this->resolve('currencies', (string) ($row['currency'] ?? ''));
        $currency = $this->store->one('SELECT code FROM currency WHERE id=?', [$currencyId]);
        $status = match (strtolower((string) ($row['status'] ?? ''))) {
            'active' => 'active', 'inactive' => 'suspended', 'closed' => 'canceled',
            default => throw new SafeException('Client status requires an explicit reviewed mapping.'),
        };
        return $data + [
            'aid' => 'whmcs:' . $this->source . ':' . $row['id'], 'role' => 'client',
            'currency' => $currency['code'], 'status' => $status, 'email_approved' => 0,
            // No WHMCS password/hash is extracted. Only a random, discarded credential is hashed.
            // Administrator must explicitly initiate a reset/activation after reconciliation.
            'pass' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'tax_exempt' => !empty($row['taxexempt']) ? 1 : 0,
            'client_group_id' => empty($row['groupid']) ? null : $this->resolve('clientgroups', (string) $row['groupid']),
            'created_at' => $this->registrationDate($row['datecreated'] ?? null), 'updated_at' => null,
        ];
    }

    private function resolve(string $suffix, string $key): int
    {
        $mapping = $this->store->one('SELECT destination_id FROM whmcsmigrator_identity WHERE source_id=? AND table_name=? AND source_key=? AND destination_entity=?', [$this->source, $this->prefix . $suffix, $key, self::ENTITIES[$suffix]]);
        if (!$mapping || !$mapping['destination_id']) {
            throw new SafeException('A required destination relationship has not been imported. Include dependencies or retry after resolving them.');
        }

        return (int) $mapping['destination_id'];
    }

    private function insert(string $table, array $data): int
    {
        $columns = $this->store->all('SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        $definitions = array_column($columns, null, 'COLUMN_NAME');
        foreach ($data as $column => $value) {
            if (!isset($definitions[$column])) {
                throw new SafeException('Destination schema differs from the reviewed adapter. Native insert refused.');
            }
            $limit = $definitions[$column]['CHARACTER_MAXIMUM_LENGTH'];
            if ($value !== null && $limit !== null && mb_strlen((string) $value, 'UTF-8') > (int) $limit) {
                throw new SafeException('A native field exceeds the destination size. The original is archived; no truncation was performed.');
            }
            if ($value !== null && str_contains($definitions[$column]['DATA_TYPE'], 'text') && strlen((string) $value) > (int) $definitions[$column]['CHARACTER_OCTET_LENGTH']) {
                throw new SafeException('A native text field exceeds the destination byte limit. The original remains archived.');
            }
            if ($value !== null && preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint)\z/D', $definitions[$column]['DATA_TYPE']) && !preg_match('/\A-?\d+\z/D', (string) $value)) {
                throw new SafeException('A native integer field contains an incompatible value. No implicit numeric conversion is allowed.');
            }
        }
        $this->store->execute('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($data)) . '`) VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')', array_values($data));

        return (int) $this->store->pdo->lastInsertId();
    }
}
