# 0.1 — experimental development preview

**Not a production-complete WHMCS migration. Do not cut over billing with this release.**

Author: **Monlam Makhampa**. This extension is **vibe coded** and requires
independent security review before production use.

Display name: **WHMCStoFossbilling**. Actual import direction:
**WHMCS 8.x → FOSSBilling 0.8.9**.

## Included

- Read-only WHMCS schema/source inspection, reviewed mapping, dry-run eligibility
  checks, private archives, resume/retry and CSV/JSON reports.
- Supported native clients/catalog/base-currency pricing/add-ons, inactive
  credential-free server metadata, closed support and email history.
- Separately authorized historical native subset: eligible inactive hosting,
  representable paid/canceled/refunded invoices, processed payments/refunds,
  verified complete credit aggregates and extracted-subset financial assessments.
- Readable default CA path with a bundled Mozilla/curl trust store and custom
  provider-CA override; TLS/hostname verification remains enabled.
- Explicit server-side public IPv4 lookup, copy/refresh controls, the supplied
  branding icon, timezone dropdown with separate verification, and default
  version auto-detection.

## Important exclusions

Active/suspended/pending hosting, all native domain orders, unpaid/draft invoices,
unsupported tax/currency/allocation cases and complete debt/FX reconstruction
remain blocked or incomplete. Archive-only records are not successful native
migrations. Assessments are not live-source cutover reconciliation.

No hosting, registrar, gateway or notification operation is intentionally invoked.
Supported native subset tests do not prove global automation isolation or safety
with arbitrary third-party integrations. Use an otherwise idle private test
destination and a static/redacted source copy first.

## Installation and recovery

Back up and verify restoration of the destination database. Extract the
`Whmcstofossbilling/` directory into FOSSBilling's `modules/`, then activate it in
Extensions. No Composer update or core modification is required.

Existing preview installations may need **Upgrade extension tables only**.
Native runs authorized before capability revision 2 require a new reviewed plan.
Uninstall is not rollback and retains imported records/evidence.

Read [README](../README.md), [compatibility](COMPATIBILITY.md),
[operations](OPERATIONS.md), [testing](TESTING.md) and
[remaining work](REMAINING-WORK.md) before importing.

The release archive excludes `.local/`, credentials, source/customer snapshots,
test logs and development browser dependencies. The public root CA bundle has
its own provenance/MPL-2.0 notice under `certificates/`.
