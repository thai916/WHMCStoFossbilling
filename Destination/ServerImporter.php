<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Destination;

use Box\Mod\Whmcsmigrator\Security\SafeException;

final class ServerImporter
{
    public function __construct(private readonly Store $store, private readonly int $source, private readonly string $prefix)
    {
    }

    public function import(array $row, string $checksum, string $run): string
    {
        $p = new Persistence($this->store, $this->source, $this->prefix);
        $key = (string) $row['id'];
        if ($p->unchanged('servers', $key, 'service_hosting_server', $checksum)) return 'skipped';
        $manager = match (strtolower((string) ($row['type'] ?? ''))) {
            'cpanel', 'whm' => 'Whm', 'directadmin' => 'Directadmin',
            'plesk' => 'Plesk', 'hestia', 'hestiacp' => 'Hestia', 'cwp' => 'CWP',
            default => throw new SafeException('Source control panel has no verified installed adapter mapping. Server remains archived only.'),
        };
        if (!empty($row['ipaddress']) && !filter_var($row['ipaddress'], FILTER_VALIDATE_IP)) throw new SafeException('Server IP address is invalid.');
        $port = empty($row['port']) ? null : filter_var($row['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) throw new SafeException('Server port is invalid.');
        $id = $p->insert('service_hosting_server', [
            'name' => $row['name'] ?? '', 'hostname' => $row['hostname'] ?? null,
            'ip' => $row['ipaddress'] ?? null, 'assigned_ips' => $row['assignedips'] ?? null,
            'manager' => $manager, 'active' => 0, 'port' => $port,
            'secure' => in_array(strtolower((string) ($row['secure'] ?? '')), ['1', 'on', 'yes', 'true'], true) ? 1 : 0,
            'ns1' => $row['nameserver1'] ?? null, 'ns2' => $row['nameserver2'] ?? null,
            'ns3' => $row['nameserver3'] ?? null, 'ns4' => $row['nameserver4'] ?? null,
            // Do not copy even a source username into an operational credential bundle.
            'username' => null, 'password' => null, 'accesshash' => null,
            'config' => Store::json(['legacy_source_id' => $this->source, 'legacy_server_id' => $key, 'credentials_required' => true]),
            'created_at' => null, 'updated_at' => null,
        ]);
        $p->track('servers', $key, 'service_hosting_server', $id, $checksum, $run);

        return 'imported';
    }
}
