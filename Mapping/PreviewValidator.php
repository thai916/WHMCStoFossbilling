<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Mapping;

use Box\Mod\Whmcsmigrator\Destination\ProductPricing;
use Box\Mod\Whmcsmigrator\Finance\Decimal;
use Box\Mod\Whmcsmigrator\Security\SafeException;

/** Pure eligibility/value checks. Not a claim that insertion/dependency resolution succeeds. */
final class PreviewValidator
{
    public static function validate(string $suffix, array $row, ?string $timezone): void
    {
        if ($suffix === 'pricing' && in_array($row['type'] ?? '', ['product', 'addon'], true)) ProductPricing::matrix($row, 'recurrent');
        if ($suffix === 'products' && (!in_array($row['type'] ?? '', ['hostingaccount', 'reselleraccount', 'server', 'other'], true) || !in_array($row['paytype'] ?? '', ['free', 'onetime', 'recurring'], true))) throw new SafeException('Product type/payment mode has no reviewed native mapping.');
        if ($suffix === 'tickets' && strtolower((string) ($row['status'] ?? '')) !== 'closed') throw new SafeException('Open ticket native isolation is not verified.');
        if (in_array($suffix, ['tickets', 'ticketreplies', 'ticketnotes', 'emails', 'accounts', 'credit'], true)) {
            if (EventDate::utc($row['date'] ?? null, $timezone) === null) throw new SafeException('Required historical timestamp is missing.');
        }
        if ($suffix === 'hosting') {
            if (!in_array(strtolower((string) ($row['domainstatus'] ?? '')), ['terminated', 'cancelled', 'canceled', 'fraud'], true)) throw new SafeException('Active/suspended/pending native hosting isolation is not verified.');
            if (EventDate::utc($row['regdate'] ?? null, $timezone) === null) throw new SafeException('Service registration date is missing.');
            Decimal::money($row['amount'] ?? '');
        }
        if ($suffix === 'invoices') {
            if (!in_array(strtolower((string) ($row['status'] ?? '')), ['paid', 'refunded', 'cancelled', 'canceled'], true)) throw new SafeException('Unpaid/draft historical invoices are vulnerable to native expiry cleanup.');
            if (empty($row['currency'])) throw new SafeException('Historical invoice currency is unresolved.');
            $subtotal = Decimal::money($row['subtotal'] ?? '');
            $tax = Decimal::money($row['tax'] ?? '');
            if (Decimal::money($row['tax2'] ?? '0') !== '0.00' || bcadd($subtotal, $tax, 2) !== Decimal::money($row['total'] ?? '')) throw new SafeException('Invoice does not fit the supported native single-tax/subtotal model.');
            if (EventDate::utc($row['date'] ?? null, $timezone) === null) throw new SafeException('Invoice creation date is missing.');
            if (strtolower((string) $row['status']) === 'paid' && EventDate::utc($row['datepaid'] ?? null, $timezone) === null) throw new SafeException('Paid invoice payment date is missing.');
        }
        if ($suffix === 'accounts') {
            $in = Decimal::money($row['amountin'] ?? '');
            $out = Decimal::money($row['amountout'] ?? '');
            if (bccomp($in, '0', 2) < 0 || bccomp($out, '0', 2) < 0 || ($in !== '0.00' && $out !== '0.00')) throw new SafeException('Payment movement requires an unsupported allocation transform.');
            if ($in === '0.00' && $out === '0.00') throw new SafeException('A fees-only or empty movement cannot become a fabricated zero-value payment.');
            if (empty($row['invoiceid']) || empty($row['currency'])) throw new SafeException('Payment invoice/currency dependency is unresolved.');
        }
    }
}
