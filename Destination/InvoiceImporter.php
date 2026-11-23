<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Destination;

use Box\Mod\Whmcstofossbilling\Archive\Sanitizer;
use Box\Mod\Whmcstofossbilling\Finance\Decimal;
use Box\Mod\Whmcstofossbilling\Mapping\EventDate;
use Box\Mod\Whmcstofossbilling\Security\SafeException;

/** Atomic publication of a bounded historical invoice; never calls approval/payment APIs. */
final class InvoiceImporter
{
    private readonly Persistence $p;

    public function __construct(private readonly Store $store, private readonly int $source, private readonly string $prefix, private readonly ?string $timezone)
    {
        $this->p = new Persistence($store, $source, $prefix);
    }

    public function import(array $row, string $checksum, string $run): string
    {
        $key = (string) $row['id'];
        $status = match (strtolower((string) ($row['status'] ?? ''))) {
            'paid' => 'paid', 'cancelled', 'canceled' => 'canceled', 'refunded' => 'refunded',
            default => throw new SafeException('Unpaid/draft invoice import is blocked: core expiry cleanup ignores approval and can delete historical invoices. Source invoice remains archived.'),
        };
        $cursor = $this->store->one('SELECT * FROM whmcstofossbilling_cursor WHERE run_id=? AND table_name=?', [$run, $this->prefix . 'invoiceitems']);
        if (!$cursor || $cursor['status'] !== 'scanned' || (int) $cursor['failed'] !== 0 || $cursor['processed'] !== $cursor['expected']) {
            throw new SafeException('All selected invoice items must finish extraction without errors before native invoice publication.');
        }
        $items = $this->store->all("SELECT a.source_key,a.payload,a.checksum FROM whmcstofossbilling_snapshot_link l JOIN whmcstofossbilling_snapshot a ON a.id=l.snapshot_id WHERE l.run_id=? AND l.table_name=? AND l.field_name='invoiceid' AND l.target_key=? ORDER BY a.id LIMIT 201", [$run, $this->prefix . 'invoiceitems', $key]);
        if (count($items) > 200) throw new SafeException('Large invoice requires staged native publication; no oversized aggregate was partially created.');
        if (!$items) throw new SafeException('Invoice has no extracted line items. Native publication refused.');
        if ($this->p->unchanged('invoices', $key, 'invoice', $checksum)) {
            foreach ($items as $item) {
                if (!$this->p->unchanged('invoiceitems', $item['source_key'], 'invoice_item', $item['checksum'])) throw new SafeException('An invoice child has no verified native identity.');
            }

            return 'skipped';
        }
        $client = $this->p->resolve('clients', (string) ($row['userid'] ?? ''), 'client');
        // Do not silently substitute today's client currency for an unknown historical invoice currency.
        if (empty($row['currency'])) throw new SafeException('Historical invoice currency is not explicit in the source. A reviewed currency-resolution policy is required.');
        $currencyId = $this->p->resolve('currencies', (string) $row['currency'], 'currency');
        $currency = $this->store->one('SELECT code FROM currency WHERE id=?', [$currencyId])['code'];
        $subtotal = $taxable = '0.00';
        $lines = [];
        foreach ($items as $item) {
            $line = json_decode($item['payload'], true, 128, JSON_THROW_ON_ERROR);
            if ((string) ($line['userid'] ?? $row['userid']) !== (string) $row['userid']) throw new SafeException('Invoice line owner conflicts with the invoice owner.');
            $amount = Decimal::money($line['amount'] ?? '');
            $subtotal = bcadd($subtotal, $amount, 2);
            $taxed = !empty($line['taxed']);
            if ($taxed) $taxable = bcadd($taxable, $amount, 2);
            $lines[] = [$item, $line, $amount, $taxed];
        }
        $sourceSubtotal = Decimal::money($row['subtotal'] ?? '');
        $tax = Decimal::money($row['tax'] ?? '');
        $tax2 = Decimal::money($row['tax2'] ?? '0');
        $rate = Decimal::money($row['taxrate'] ?? '0', 6);
        $total = Decimal::money($row['total'] ?? '');
        if ($sourceSubtotal !== $subtotal || $tax2 !== '0.00' || Decimal::tax($taxable, $rate) !== $tax || bcadd($subtotal, $tax, 2) !== $total) {
            throw new SafeException('Invoice tax/subtotal/total cannot be represented exactly by the native single-tax model. No balancing lines or altered totals were generated.');
        }
        $number = trim((string) ($row['invoicenum'] ?? ''));
        // FOSSBilling renders nonnumeric nr values as its ID. Keep arbitrary legacy
        // numbers searchable in the archive and use a collision-checked separate numeric series.
        $nativeNumber = ctype_digit($number) && strlen($number) <= 15 ? $number : $key;
        $series = 'WHMCS-' . $this->source . '-';
        if ($this->store->one('SELECT id FROM invoice WHERE serie=? AND nr=?', [$series, $nativeNumber])) throw new SafeException('Historical invoice number collides with an existing destination number.');
        $created = EventDate::utc($row['date'] ?? null, $this->timezone) ?? throw new SafeException('Invoice creation date is missing.');
        $due = EventDate::utc($row['duedate'] ?? null, $this->timezone);
        $paid = empty($row['datepaid']) || str_starts_with((string) $row['datepaid'], '0000-00-00') ? null : EventDate::utc($row['datepaid'], $this->timezone);
        if ($status === 'paid' && $paid === null) throw new SafeException('Paid invoice has no valid original payment date.');
        // The client invoice API always forces approved=true. Eligible historical
        // invoices are non-unpaid, so approval only makes them visible; it does
        // not add a payable/actionable state.
        $approved = in_array($status, ['paid', 'canceled', 'refunded'], true) ? 1 : 0;
        $id = $this->p->insert('invoice', [
            'client_id' => $client, 'serie' => $series, 'nr' => $nativeNumber,
            'hash' => bin2hex(random_bytes(32)), 'hash_expires_at' => '1970-01-01 00:00:00',
            'currency' => $currency, 'currency_rate' => isset($row['currency_rate']) ? Decimal::money($row['currency_rate'], 6) : null,
            'credit' => Decimal::money($row['credit'] ?? '0'), 'status' => $status,
            'approved' => $approved, 'gateway_id' => null, 'taxname' => $tax === '0.00' ? null : 'Legacy tax',
            'taxrate' => $rate,
            // Original notes may have unknown visibility and remain in the private archive.
            'notes' => 'Historical WHMCS record. Original number: ' . Sanitizer::text($number !== '' ? $number : $key) . '. Original seller/buyer legal billing snapshots may be unavailable; this is not a reissued legal invoice.',
            'created_at' => $created, 'updated_at' => $created, 'due_at' => $due, 'paid_at' => $paid,
        ]);
        foreach ($lines as [$item, $line, $amount, $taxed]) {
            $lineId = $this->p->insert('invoice_item', [
                'invoice_id' => $id, 'type' => 'custom', 'task' => 'void', 'status' => 'executed',
                'charged' => 1, 'taxed' => (int) $taxed, 'quantity' => 1, 'price' => $amount,
                'title' => Sanitizer::text((string) ($line['description'] ?? '')), 'created_at' => $created, 'updated_at' => $created,
            ]);
            $stored = $this->store->one('SELECT CAST(price AS DECIMAL(18,2)) AS amount FROM invoice_item WHERE id=?', [$lineId]);
            if ($stored['amount'] !== $amount) throw new SafeException('Destination floating storage changed a source line amount. Native invoice aggregate rolled back.');
            $this->p->track('invoiceitems', $item['source_key'], 'invoice_item', $lineId, $item['checksum'], $run);
            // The prior extraction disposition becomes native only when publication commits.
            $attempt = $this->store->one('SELECT status FROM whmcstofossbilling_attempt WHERE run_id=? AND table_name=? AND source_key=?', [$run, $this->prefix . 'invoiceitems', $item['source_key']]);
            if ($attempt && in_array($attempt['status'], ['archived', 'skipped'], true)) {
                $column = $attempt['status'];
                $this->store->execute("UPDATE whmcstofossbilling_cursor SET `$column`=`$column`-1,imported=imported+1 WHERE run_id=? AND table_name=?", [$run, $this->prefix . 'invoiceitems']);
                $this->store->execute("UPDATE whmcstofossbilling_attempt SET status='imported',updated_at=UTC_TIMESTAMP() WHERE run_id=? AND table_name=? AND source_key=?", [$run, $this->prefix . 'invoiceitems', $item['source_key']]);
            }
        }
        $stored = $this->store->one('SELECT CAST(credit AS DECIMAL(18,2)) AS amount FROM invoice WHERE id=?', [$id]);
        if ($stored['amount'] !== Decimal::money($row['credit'] ?? '0')) throw new SafeException('Destination floating storage changed historical invoice credit.');
        $this->p->track('invoices', $key, 'invoice', $id, $checksum, $run);

        return 'imported';
    }
}
