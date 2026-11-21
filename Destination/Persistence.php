<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Destination;

use Box\Mod\Whmcsmigrator\Security\SafeException;

/** Shared validated, non-eventful persistence. Never accepts an arbitrary table from an API. */
final class Persistence
{
    public const TABLES = ['product', 'product_payment', 'service_hosting_hp', 'service_hosting_server', 'client_order', 'service_hosting', 'invoice', 'invoice_item', 'transaction', 'client_balance', 'support_helpdesk', 'support_ticket', 'support_ticket_message', 'support_ticket_note', 'activity_client_email'];
    private array $definitions = [];

    public function __construct(private readonly Store $store, private readonly int $source, private readonly string $prefix)
    {
    }

    public function insert(string $table, array $data): int
    {
        $this->validate($table, $data);
        $this->store->execute('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($data)) . '`) VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')', array_values($data));

        return (int) $this->store->pdo->lastInsertId();
    }

    public function update(string $table, int $id, array $data): void
    {
        $this->validate($table, $data);
        $set = implode(',', array_map(static fn (string $column): string => '`' . $column . '`=?', array_keys($data)));
        $this->store->execute('UPDATE `' . $table . '` SET ' . $set . ' WHERE id=?', [...array_values($data), $id]);
        // Refresh only verified module-owned references after an intentional module write.
        $current = $this->store->one('SELECT * FROM `' . $table . '` WHERE id=?', [$id]);
        $this->store->execute('UPDATE whmcsmigrator_identity SET destination_checksum=? WHERE source_id=? AND destination_entity=? AND destination_id=?', [hash('sha256', Store::json($current)), $this->source, $table, $id]);
    }

    public function mapping(string $suffix, string $key, string $entity): ?array
    {
        return $this->store->one('SELECT * FROM whmcsmigrator_identity WHERE source_id=? AND table_name=? AND source_key=? AND destination_entity=?', [$this->source, $this->prefix . $suffix, $key, $entity]);
    }

    public function resolve(string $suffix, string $key, string $entity): int
    {
        $mapping = $this->mapping($suffix, $key, $entity);
        if (!$mapping || !$mapping['destination_id']) {
            throw new SafeException('A required native dependency has not been imported. Resolve dependencies before retrying.');
        }
        $this->verify($mapping);

        return (int) $mapping['destination_id'];
    }

    public function verify(array $mapping): void
    {
        $entity = $mapping['destination_entity'];
        if (!in_array($entity, [...self::TABLES, 'client', 'currency', 'client_group', 'product_category'], true)) {
            throw new SafeException('Unrecognized destination identity.');
        }
        $row = $this->store->one('SELECT * FROM `' . $entity . '` WHERE id=?', [$mapping['destination_id']]);
        if (!$row || !hash_equals((string) $mapping['destination_checksum'], hash('sha256', Store::json($row)))) {
            throw new SafeException('A linked native record changed or disappeared. Automatic overwrite refused.');
        }
    }

    public function unchanged(string $suffix, string $key, string $entity, string $checksum): bool
    {
        $mapping = $this->mapping($suffix, $key, $entity);
        if (!$mapping) return false;
        $this->verify($mapping);
        if ($mapping['checksum'] !== $checksum) {
            throw new SafeException('A previously imported source record changed. Selective update approval is required.');
        }

        return true;
    }

    public function track(string $suffix, string $key, string $entity, int $id, string $checksum, string $run): void
    {
        $row = $this->store->one('SELECT * FROM `' . $entity . '` WHERE id=?', [$id]);
        $this->store->execute('INSERT INTO whmcsmigrator_identity (source_id,table_name,source_key,destination_entity,destination_id,checksum,destination_checksum,run_id,created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())', [$this->source, $this->prefix . $suffix, $key, $entity, $id, $checksum, hash('sha256', Store::json($row)), $run]);
    }

    private function validate(string $table, array $data): void
    {
        if (!in_array($table, self::TABLES, true) || !$data) {
            throw new SafeException('Unreviewed native destination rejected.');
        }
        $definitions = $this->definitions[$table] ??= array_column($this->store->all('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_OCTET_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]), null, 'COLUMN_NAME');
        foreach ($data as $name => $value) {
            $field = $definitions[$name] ?? throw new SafeException('Destination schema does not match the reviewed native adapter.');
            if ($value === null) continue;
            if (is_float($value)) throw new SafeException('Floating-point native persistence input is not allowed.');
            if ($field['CHARACTER_MAXIMUM_LENGTH'] !== null && mb_strlen((string) $value, 'UTF-8') > (int) $field['CHARACTER_MAXIMUM_LENGTH']) {
                throw new SafeException('A native field would be truncated. Original data retained in the archive.');
            }
            if (str_contains($field['DATA_TYPE'], 'text') && strlen((string) $value) > (int) $field['CHARACTER_OCTET_LENGTH']) {
                throw new SafeException('A native text field exceeds its byte limit. No truncation performed.');
            }
            if (preg_match('/int\z/D', $field['DATA_TYPE']) && !preg_match('/\A-?\d+\z/D', (string) $value)) {
                throw new SafeException('Invalid native integer value.');
            }
        }
    }
}
