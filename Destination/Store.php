<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Destination;

use Box\Mod\Whmcsmigrator\Security\SafeException;

final class Store
{
    public function __construct(public readonly \PDO $pdo)
    {
    }

    public function all(string $sql, array $values = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function one(string $sql, array $values = []): ?array
    {
        return $this->all($sql, $values)[0] ?? null;
    }

    public function execute(string $sql, array $values = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return $statement->rowCount();
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function source(int $id): array
    {
        return $this->one('SELECT * FROM whmcsmigrator_source WHERE id = ?', [$id]) ?? throw new SafeException('Source profile not found.');
    }

    public function run(string $id): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw new SafeException('Invalid migration run identifier.');
        }

        return $this->one('SELECT * FROM whmcsmigrator_run WHERE id = ?', [$id]) ?? throw new SafeException('Migration run not found.');
    }

    public function schemaReady(): bool
    {
        $required = ['price', 'archive_link', 'snapshot', 'snapshot_link', 'assessment', 'reconcile_state', 'reconcile_total', 'credit_state', 'credit_result', 'legacy_reference'];
        $rows = $this->all('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($required), '?')) . ')', array_map(static fn (string $name): string => 'whmcsmigrator_' . $name, $required));
        $names = array_column($rows, 'TABLE_NAME');
        foreach ($required as $name) if (!in_array('whmcsmigrator_' . $name, $names, true)) return false;

        return true;
    }

    public function lock(string $name): void
    {
        if ((int) ($this->one('SELECT GET_LOCK(?, 0) AS acquired', [$name])['acquired'] ?? 0) !== 1) {
            throw new SafeException('Another migration operation is active. Wait before retrying.');
        }
    }

    public function unlock(string $name): void
    {
        $this->one('SELECT RELEASE_LOCK(?) AS released', [$name]);
    }

    public function verifyDestination(): array
    {
        $mode = (string) ($this->one('SELECT @@SESSION.sql_mode AS mode')['mode'] ?? '');
        if (!preg_match('/(?:^|,)(?:STRICT_TRANS_TABLES|STRICT_ALL_TABLES)(?:,|$)/', $mode)) {
            throw new SafeException('Destination strict SQL mode is required to prevent silent coercion/truncation. No server or session settings were changed.');
        }
        $tables = $this->all("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        $required = ['client', 'client_group', 'currency', 'product', 'product_category', 'product_payment', 'client_order', 'service_hosting', 'service_hosting_server', 'service_hosting_hp', 'service_domain', 'invoice', 'invoice_item', 'transaction', 'client_balance', 'support_helpdesk', 'support_ticket', 'support_ticket_message', 'support_ticket_note', 'activity_client_email'];
        $engines = array_column($tables, 'ENGINE', 'TABLE_NAME');
        foreach ($required as $table) {
            if (($engines[$table] ?? '') !== 'InnoDB') {
                throw new SafeException('A required destination table is missing or does not support transactions.');
            }
        }
        $triggers = $this->all('SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()');
        foreach ($triggers as $trigger) {
            if (in_array($trigger['EVENT_OBJECT_TABLE'], ['client', 'client_group', 'currency', 'product', 'product_category', 'product_payment', ...Persistence::TABLES], true)) {
                throw new SafeException('A destination business table has an unreviewed database trigger. Native side-effect isolation cannot be verified.');
            }
        }
        $columns = $this->all('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION');
        $columns = array_values(array_filter($columns, static fn (array $r): bool => !str_starts_with($r['TABLE_NAME'], 'whmcsmigrator_')));

        return ['tables' => $required, 'fingerprint' => hash('sha256', self::json($columns))];
    }
}
