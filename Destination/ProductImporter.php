<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Destination;

use Box\Mod\Whmcsmigrator\Archive\Sanitizer;
use Box\Mod\Whmcsmigrator\Security\SafeException;

final class ProductImporter
{
    private readonly Persistence $persistence;

    public function __construct(private readonly Store $store, private readonly int $source, private readonly string $prefix)
    {
        $this->persistence = new Persistence($store, $source, $prefix);
    }

    public function import(string $suffix, array $row, string $checksum, string $run): string
    {
        $key = (string) $row['id'];
        if ($this->persistence->unchanged($suffix, $key, 'product', $checksum)) {
            foreach (['product_payment', 'service_hosting_hp'] as $entity) {
                $child = $this->persistence->mapping($suffix, $key, $entity);
                if ($child) $this->persistence->verify($child);
            }

            return 'skipped';
        }
        $addon = $suffix === 'addons';
        $type = $addon ? 'custom' : match ($row['type'] ?? '') {
            'hostingaccount', 'reselleraccount', 'server' => 'hosting',
            'other' => 'custom',
            default => throw new SafeException('Product type has no reviewed native representation.'),
        };
        $paymentType = $addon ? match (strtolower((string) ($row['billingcycle'] ?? ''))) {
            'free', 'free account' => 'free', 'one time', 'onetime' => 'once',
            'monthly', 'quarterly', 'semi-annually', 'annually', 'biennially', 'triennially', 'recurring' => 'recurrent',
            default => throw new SafeException('Add-on billing cycle is unsupported.'),
        } : match ($row['paytype'] ?? '') {
            'free' => 'free', 'onetime' => 'once', 'recurring' => 'recurrent',
            default => throw new SafeException('Product payment type is unsupported.'),
        };
        $payment = $this->persistence->insert('product_payment', [
            'type' => $paymentType, 'w_enabled' => 0, 'm_enabled' => 0, 'q_enabled' => 0,
            'b_enabled' => 0, 'a_enabled' => 0, 'bia_enabled' => 0, 'tria_enabled' => 0,
        ]);
        $this->persistence->track($suffix, $key, 'product_payment', $payment, $checksum, $run);
        $config = ['import' => true, 'legacy_source_id' => $this->source, 'legacy_product_id' => $key];
        if (!$addon && $type === 'hosting') {
            // Only explicit package-name fields are trusted. Opaque WHMCS moduleconfigoptionN
            // slots have module-specific semantics and are deliberately not guessed.
            $package = trim((string) ($row['packagename'] ?? $row['package'] ?? ''));
            if ($package !== '') {
                $plan = $this->persistence->insert('service_hosting_hp', ['name' => $package, 'config' => Store::json(['legacy_module' => (string) ($row['servertype'] ?? '')]), 'created_at' => null, 'updated_at' => null]);
                $this->persistence->track($suffix, $key, 'service_hosting_hp', $plan, $checksum, $run);
                $config['hosting_plan_id'] = $plan;
            }
            if (!empty($row['servergroup'])) {
                $config['legacy_server_group'] = (string) $row['servergroup'];
                $members = $this->store->all("SELECT a.payload FROM whmcsmigrator_snapshot_link l JOIN whmcsmigrator_snapshot a ON a.id=l.snapshot_id WHERE l.run_id=? AND l.table_name=? AND l.field_name='groupid' AND l.target_key=? ORDER BY a.id LIMIT 2", [$run, $this->prefix . 'servergroupsrel', (string) $row['servergroup']]);
                if (count($members) === 1) {
                    $server = json_decode($members[0]['payload'], true, 128, JSON_THROW_ON_ERROR);
                    $config['server_id'] = $this->persistence->resolve('servers', (string) ($server['serverid'] ?? ''), 'service_hosting_server');
                }
            }
        }
        $data = [
            'product_category_id' => $addon ? null : $this->persistence->resolve('productgroups', (string) ($row['gid'] ?? ''), 'product_category'),
            'product_payment_id' => $payment, 'title' => $row['name'] ?? '',
            'description' => \FOSSBilling\Tools::sanitizeContent(Sanitizer::text((string) ($row['description'] ?? ''))),
            'slug' => 'whmcs-' . $this->source . '-' . $suffix . '-' . $key,
            'active' => 0, 'status' => 'disabled', 'hidden' => 1, 'setup' => 'manual',
            'type' => $type, 'is_addon' => (int) $addon, 'config' => Store::json($config),
            'stock_control' => !empty($row['stockcontrol']) ? 1 : 0, 'quantity_in_stock' => $row['qty'] ?? 0,
            'priority' => $row['order'] ?? 0, 'created_at' => null, 'updated_at' => null,
        ];
        $id = $this->persistence->insert('product', $data);
        $this->persistence->track($suffix, $key, 'product', $id, $checksum, $run);
        if ($addon && !empty($row['packages'])) {
            $packages = explode(',', (string) $row['packages']);
            if (count($packages) > 200) throw new SafeException('Add-on has too many parent links for a bounded aggregate; staged relationship processing is required.');
            foreach (array_unique($packages) as $productKey) {
                $productKey = trim($productKey);
                if (!ctype_digit($productKey) || $productKey === '0') throw new SafeException('Invalid add-on parent product reference.');
                $parentId = $this->persistence->resolve('products', $productKey, 'product');
                $parent = $this->store->one('SELECT addons FROM product WHERE id=?', [$parentId]);
                $ids = json_decode($parent['addons'] ?? '[]', true, 128, JSON_THROW_ON_ERROR);
                if (!is_array($ids)) throw new SafeException('Destination add-on relationship format is invalid.');
                $ids[] = $id;
                $this->persistence->update('product', $parentId, ['addons' => Store::json(array_values(array_unique($ids)))]);
            }
        }

        return 'imported';
    }
}
