<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Destination;

use Box\Mod\Whmcstofossbilling\Finance\Decimal;
use Box\Mod\Whmcstofossbilling\Mapping\EventDate;
use Box\Mod\Whmcstofossbilling\Security\SafeException;

final class PaymentImporter
{
    public function __construct(private readonly Store $store, private readonly int $source, private readonly string $prefix, private readonly ?string $timezone)
    {
    }

    public function import(array $row, string $checksum, string $run): string
    {
        $p = new Persistence($this->store, $this->source, $this->prefix);
        if ($p->unchanged('accounts', (string) $row['id'], 'transaction', $checksum)) return 'skipped';
        $runRecord = $this->store->run($run);
        $schema = json_decode($runRecord['schema_json'], true, 128, JSON_THROW_ON_ERROR);
        foreach ($schema as $name => $definition) {
            if (in_array($definition['suffix'], ['invoicepayment', 'invoicepayments', 'invoice_transactions'], true)) {
                $cursor = $this->store->one('SELECT expected,status,failed FROM whmcstofossbilling_cursor WHERE run_id=? AND table_name=?', [$run, $name]);
                if (!$cursor || (int) $cursor['expected'] > 0 || (int) $cursor['failed'] > 0) throw new SafeException('Source allocation tables require a reviewed allocation adapter. A transaction was not incorrectly assigned wholly to one invoice.');
            }
        }
        $in = Decimal::money($row['amountin'] ?? '');
        $out = Decimal::money($row['amountout'] ?? '');
        Decimal::money($row['fees'] ?? '0');
        if (bccomp($in, '0', 2) < 0 || bccomp($out, '0', 2) < 0 || ($in !== '0.00' && $out !== '0.00')) {
            throw new SafeException('Payment row has unsupported negative or simultaneous in/out movements. Original gross amounts and fees remain archived.');
        }
        if ($in === '0.00' && $out === '0.00') throw new SafeException('Source movement has no receipt/refund amount. No artificial zero-value payment was created.');
        $invoiceMapping = $p->mapping('invoices', (string) ($row['invoiceid'] ?? ''), 'invoice');
        if (!$invoiceMapping) throw new SafeException('Payment has no safely imported native invoice. Allocation/client/gateway history remains archived; no fabricated invoice was created.');
        $p->verify($invoiceMapping);
        $invoice = $this->store->one('SELECT * FROM invoice WHERE id=?', [$invoiceMapping['destination_id']]);
        $client = $p->resolve('clients', (string) ($row['userid'] ?? ''), 'client');
        $currency = $p->resolve('currencies', (string) ($row['currency'] ?? ''), 'currency');
        $code = $this->store->one('SELECT code FROM currency WHERE id=?', [$currency])['code'];
        if ((int) $invoice['client_id'] !== $client || $invoice['currency'] !== $code) throw new SafeException('Payment owner/currency conflicts with its invoice.');
        $date = EventDate::utc($row['date'] ?? null, $this->timezone) ?? throw new SafeException('Historical transaction date is missing.');
        $id = $p->insert('transaction', [
            'invoice_id' => $invoiceMapping['destination_id'], 'gateway_id' => null,
            'txn_id' => (string) ($row['transid'] ?? ''), 'txn_status' => 'historical',
            'type' => $out !== '0.00' ? 'refund' : 'payment', 'amount' => $out !== '0.00' ? $out : $in,
            'currency' => $code, 'status' => 'processed', 'validate_ipn' => 0,
            'ipn' => null, 'ipn_hash' => null,
            'note' => 'Historical WHMCS transaction; original gateway and fees are preserved in the private archive.',
            'created_at' => $date, 'updated_at' => $date,
        ]);
        $p->track('accounts', (string) $row['id'], 'transaction', $id, $checksum, $run);

        return 'imported';
    }
}
