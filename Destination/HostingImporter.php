<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Destination;

use Box\Mod\Whmcstofossbilling\Finance\Decimal;
use Box\Mod\Whmcstofossbilling\Mapping\EventDate;
use Box\Mod\Whmcstofossbilling\Security\SafeException;

/** Historical non-operational services only. Active/suspended/pending remain blocked. */
final class HostingImporter
{
    public function __construct(private readonly Store $store, private readonly int $source, private readonly string $prefix, private readonly ?string $timezone)
    {
    }

    public function import(array $row, string $checksum, string $run): string
    {
        $legacyStatus = (string) ($row['domainstatus'] ?? '');
        if (!in_array(strtolower($legacyStatus), ['terminated', 'cancelled', 'canceled', 'fraud'], true)) {
            throw new SafeException('Active/suspended/pending hosting import remains blocked: native cron can suspend, cancel or delete those orders. Full account history remains archived.');
        }
        $p = new Persistence($this->store, $this->source, $this->prefix);
        $key = (string) $row['id'];
        if ($p->unchanged('hosting', $key, 'client_order', $checksum)) {
            $p->resolve('hosting', $key, 'service_hosting');
            return 'skipped';
        }
        $client = $p->resolve('clients', (string) ($row['userid'] ?? ''), 'client');
        $product = $p->resolve('products', (string) ($row['packageid'] ?? ''), 'product');
        $server = $p->resolve('servers', (string) ($row['server'] ?? ''), 'service_hosting_server');
        $plan = $p->resolve('products', (string) $row['packageid'], 'service_hosting_hp');
        $serverData = $this->store->one('SELECT active,username,password,accesshash FROM service_hosting_server WHERE id=?', [$server]);
        if ($serverData['active'] || $serverData['username'] !== null || $serverData['password'] !== null || $serverData['accesshash'] !== null) throw new SafeException('Hosting server is no longer inactive and credential-free. Historical association refused.');
        $productData = $this->store->one('SELECT type,active,setup FROM product WHERE id=?', [$product]);
        if ($productData['type'] !== 'hosting' || $productData['active'] || $productData['setup'] !== 'manual') throw new SafeException('Hosting product is no longer a disabled manual product.');
        $clientData = $this->store->one('SELECT currency FROM client WHERE id=?', [$client]);
        $domain = strtolower(rtrim(trim((string) ($row['domain'] ?? '')), '.'));
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($domain, '.')) throw new SafeException('Historical hosting domain is not a valid hostname.');
        [$sld, $rest] = explode('.', $domain, 2);
        $ip = empty($row['dedicatedip']) ? null : $row['dedicatedip'];
        if ($ip !== null && !filter_var($ip, FILTER_VALIDATE_IP)) throw new SafeException('Historical dedicated IP is invalid.');
        $period = match (strtolower((string) ($row['billingcycle'] ?? ''))) {
            'monthly' => '1M', 'quarterly' => '3M', 'semi-annually' => '6M',
            'annually' => '1Y', 'biennially' => '2Y', 'triennially' => '3Y',
            'free account', 'one time' => null,
            default => throw new SafeException('Hosting billing cycle requires a reviewed mapping.'),
        };
        $created = EventDate::utc($row['regdate'] ?? null, $this->timezone) ?? throw new SafeException('Service registration date is missing.');
        $due = empty($row['nextduedate']) || str_starts_with((string) $row['nextduedate'], '0000-00-00') ? null : EventDate::utc($row['nextduedate'], $this->timezone);
        $terminated = empty($row['termination_date']) || str_starts_with((string) $row['termination_date'], '0000-00-00') ? null : EventDate::utc($row['termination_date'], $this->timezone);
        $service = $p->insert('service_hosting', [
            'client_id' => $client, 'service_hosting_server_id' => $server, 'service_hosting_hp_id' => $plan,
            // Hosting supports subdomains: concatenate these fields to recover the exact hostname.
            'sld' => $sld, 'tld' => '.' . $rest, 'ip' => $ip, 'username' => $row['username'] ?? null,
            'pass' => null, 'created_at' => $created, 'updated_at' => null,
        ]);
        $p->track('hosting', $key, 'service_hosting', $service, $checksum, $run);
        $amount = Decimal::money($row['amount'] ?? '');
        $order = $p->insert('client_order', [
            'client_id' => $client, 'product_id' => $product, 'service_id' => $service, 'service_type' => 'hosting',
            'title' => $domain, 'currency' => $clientData['currency'], 'price' => $amount, 'quantity' => 1,
            'period' => $period, 'status' => 'canceled', 'invoice_option' => 'no-invoice',
            // Core client/admin order lists default to hide_addons=1, which filters
            // co.group_master = 1. Without this, imported services are invisible.
            'group_master' => 1,
            'created_at' => $created, 'updated_at' => null, 'expires_at' => $due, 'canceled_at' => $terminated,
            'config' => Store::json([
                'import' => true, 'server_id' => $server, 'hosting_plan_id' => $plan,
                'username' => $row['username'] ?? null, 'legacy_source_id' => $this->source,
                'legacy_service_id' => $key, 'legacy_status' => $legacyStatus,
                'legacy_next_due_date' => $row['nextduedate'] ?? null,
                'legacy_first_payment' => $row['firstpaymentamount'] ?? null,
            ]),
        ]);
        $stored = $this->store->one('SELECT CAST(price AS DECIMAL(18,2)) AS amount FROM client_order WHERE id=?', [$order]);
        if ($stored['amount'] !== $amount) throw new SafeException('Native storage changed the recurring amount. Historical service aggregate rolled back.');
        $p->track('hosting', $key, 'client_order', $order, $checksum, $run);

        return 'imported';
    }
}
