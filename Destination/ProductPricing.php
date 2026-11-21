<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Destination;

use Box\Mod\Whmcstofossbilling\Finance\Decimal;
use Box\Mod\Whmcstofossbilling\Security\SafeException;

final class ProductPricing
{
    public const CYCLES = [
        'monthly' => ['m', 'msetupfee', '1M'], 'quarterly' => ['q', 'qsetupfee', '3M'],
        'semiannually' => ['b', 'ssetupfee', '6M'], 'annually' => ['a', 'asetupfee', '1Y'],
        'biennially' => ['bia', 'bsetupfee', '2Y'], 'triennially' => ['tria', 'tsetupfee', '3Y'],
    ];

    public function __construct(private readonly Store $store, private readonly int $source, private readonly string $prefix)
    {
    }

    public function import(array $row, string $checksum, string $run): string
    {
        // Add-on/config-option/domain price matrices have different semantics; never reinterpret as product prices.
        if (!in_array($row['type'] ?? '', ['product', 'addon'], true)) return 'archived';
        $suffix = $row['type'] === 'addon' ? 'addons' : 'products';
        $persistence = new Persistence($this->store, $this->source, $this->prefix);
        $product = $persistence->resolve($suffix, (string) ($row['relid'] ?? ''), 'product');
        $currencyId = $persistence->resolve('currencies', (string) ($row['currency'] ?? ''), 'currency');
        $currency = $this->store->one('SELECT * FROM currency WHERE id=?', [$currencyId]);
        $paymentId = $persistence->resolve($suffix, (string) $row['relid'], 'product_payment');
        $payment = $this->store->one('SELECT * FROM product_payment WHERE id=?', [$paymentId]);
        $matrix = $this->matrix($row, $payment['type']);
        $previous = $this->store->one('SELECT * FROM whmcstofossbilling_price WHERE source_id=? AND source_key=?', [$this->source, (string) $row['id']]);
        if ($previous) {
            if ($previous['checksum'] !== $checksum) throw new SafeException('Previously imported pricing changed. Native prices and existing price matrix were preserved pending approval.');
            if ($previous['native_applied']) $persistence->resolve('pricing', (string) $row['id'], 'product_payment');

            return 'skipped';
        }
        $existing = $this->store->one('SELECT id FROM whmcstofossbilling_price WHERE source_id=? AND product_id=? AND currency=?', [$this->source, $product, $currency['code']]);
        if ($existing) throw new SafeException('Multiple source price rows target the same product/currency. No price was overwritten.');
        $default = $this->store->one('SELECT code FROM currency WHERE is_default=1 ORDER BY id');
        // No implicit exchange-rate conversion. Only the actual destination base currency has native price slots.
        $native = $default && $default['code'] === $currency['code'];
        if ($native) {
            $persistence->update('product_payment', $paymentId, $matrix['native']);
            $persistence->track('pricing', (string) $row['id'], 'product_payment', $paymentId, $checksum, $run);
        }
        $this->store->execute('INSERT INTO whmcstofossbilling_price (source_id,source_key,product_id,currency,prices_json,native_applied,checksum,run_id) VALUES (?,?,?,?,?,?,?,?)', [$this->source, (string) $row['id'], $product, $currency['code'], Store::json($matrix['cycles']), (int) $native, $checksum, $run]);

        return $native ? 'imported' : 'archived';
    }

    public static function matrix(array $row, string $type): array
    {
        $cycles = $native = [];
        foreach (self::CYCLES as $column => [$target, $setup, $period]) {
            if (!array_key_exists($column, $row) || !array_key_exists($setup, $row)) {
                throw new SafeException('The source product pricing row is incomplete. No missing cycle or setup fee was replaced by zero.');
            }
            $price = Decimal::money($row[$column]);
            $fee = Decimal::money($row[$setup]);
            if (bccomp($price, '-1', 2) < 0 || bccomp($fee, '0', 2) < 0) {
                throw new SafeException('A price/setup fee has unsupported negative semantics.');
            }
            if (bccomp($price, '0', 2) < 0 && $price !== '-1.00') {
                throw new SafeException('Disabled WHMCS cycles must use the documented -1 sentinel.');
            }
            $cycles[$period] = ['amount' => $price, 'setup' => $fee, 'enabled' => bccomp($price, '0', 2) >= 0];
            $native[$target . '_price'] = $price === '-1.00' ? '0.00' : $price;
            $native[$target . '_setup_price'] = $fee;
            $native[$target . '_enabled'] = $type === 'recurrent' && $price !== '-1.00' ? 1 : 0;
        }
        if ($type === 'once') {
            if (!$cycles['1M']['enabled']) throw new SafeException('One-time product has no usable WHMCS monthly-slot price.');
            $native['once_price'] = $cycles['1M']['amount'];
            $native['once_setup_price'] = $cycles['1M']['setup'];
        }
        if (!in_array($type, ['free', 'once', 'recurrent'], true)) throw new SafeException('Unknown native product billing type.');

        return ['cycles' => $cycles, 'native' => $native];
    }
}
