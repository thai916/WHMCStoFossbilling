# FOSSB to WHMCS Migrator — 0.2 development preview

**Migration direction: WHMCS 8.x → FOSSBilling 0.8.9.** The requested display name does not reverse the source and destination.

**Author: Monlam Makhampa**

This extension is **vibe coded**. It requires independent security review and disposable-database testing before production use.

> **Not a complete WHMCS → FOSSBilling migration. Do not cut over billing with this version.**
>
> Development has continued beyond the initial preview. Supported historical native adapters and financial snapshot assessments now exist, but active/suspended/pending hosting, domain orders, unpaid/draft invoices and several incompatible financial cases remain blocked. The original complete-migration acceptance criteria have **not** been met.

## Implemented

- Independent FOSSBilling **0.8.9** module; no core or Composer edits.
- Admin navigation, four-step wizard and per-endpoint permissions.
- Session-bound POST/CSRF checks, including when global CSRF protection is off.
- Separate source PDO connection: direct database-scoped SELECT-only grants, explicit read-only transactions, no source write interface.
- Three source-only security modes: verified TLS (default), explicitly acknowledged unverified TLS, or explicitly acknowledged standard MySQL without TLS. Secure failures never downgrade.
- Readable default CA selection with an included Mozilla trust bundle, safe connection diagnostics and actual encryption/identity/table-availability reporting.
- Explicit administrator-requested server-side public IPv4 lookup and copy/refresh controls. No automatic public-IP requests.
- Automatic read-only timezone evidence inspection, confidence/method display and an optional verified application-timezone override when detection is inconclusive. No UTC or offset-to-IANA guessing.
- WHMCS version/schema inspection, conservative sensitive-table/column exclusion.
- Per-table preflight counts in separate requests.
- Editable client text-field mappings, archive/skip dispositions, mapping validation and JSON profiles.
- Batched dry run with an explicit archive/reference/historical target: source scan, client conflict checks and supported-adapter value/eligibility validation. **Not a simulation of every insertion, aggregate or financial relationship.**
- Explicit backup confirmation and authorization before non-preview runs.
- Durable per-record checkpoints, source-level database advisory locks, committed-request replay, pause/resume/cancel, bounded failed-record retry.
- Cross-run identity mappings and unchanged-record skipping. Changed native records are **not** automatically updated.
- Structured administrator-only archives, indexed relationships, original dates, disclosed exclusions/redactions and prior revisions. Per-run extracted snapshots cannot be replaced by a later run's latest archive.
- Native currencies, client groups, clients, categories and **disabled/hidden/manual products**.
- Native base-currency prices/setup fees for free, one-time, monthly, quarterly, semiannual, annual, biennial and triennial billing. Independent currency matrices are preserved separately without exchange-rate substitution.
- Native add-ons, their prices and parent-product links; inactive, credential-free server metadata.
- Explicit package-name fields and unambiguous single-server group relationships. Opaque module configuration slots are never guessed.
- Native closed tickets/opening messages/replies/private notes and redacted plain-text email history, preserving verified event dates without sending email.
- Separate historical mode: canceled/terminated/fraud hosting history as safe canceled native orders, preserving exact original status in metadata; exactly representable paid/canceled/refunded invoices and lines; processed historical payments/refunds; verified complete client credit aggregates.
- Resumable decimal-only extracted-baseline/native financial assessments, grouped by currency. Missing native records, currency conflicts, drift and unsupported fees remain exceptions. **Not complete debt/allocation or live-cutover reconciliation.**
- CSV/JSON reports with identities, all aggregate components and actual assessment totals. Original textual invoice numbers/ticket references are searchable in the private archive.
- PHP, security, synthetic database integration, native-loader/Twig and synthetic browser tests.

## Installation

1. Back up the destination database and verify restoration.
2. Place this directory at `modules/Whmcsmigrator/` — exact capitalization matters on Linux.
3. Use the PHP runtime serving FOSSBilling, **PHP 8.3+**, with PDO MySQL, OpenSSL, BCMath, Mbstring and JSON.
4. Log in at your FOSSBilling admin URL.
5. In **Extensions → Overview**, activate **FOSSB to WHMCS Migrator**.
6. Open **Extensions → FOSSB to WHMCS**.

When upgrading the initial preview, use **Upgrade extension tables only** if prompted. It requires explicit confirmation and configure/execute permission. It creates only missing `whmcsmigrator_*` tables; no core schema or business records are altered. Previously authorized native runs cannot silently acquire new adapters: cancel an old unfinished native run and create a newly reviewed plan.

Upgrading 0.1 to 0.2 adds no tables. Retest saved connections to collect security/timezone diagnostics. New run plans freeze the selected security mode; changing it requires a new reviewed run rather than silently changing an old authorization.

For this development installation:

```text
http://fossbill.test/admin/whmcsmigrator
```

Adjust the scheme/admin prefix for your deployment. Activation creates only extension tables; it never connects to WHMCS or imports business records.

No Composer update, Docker, external queue or cron is required. The existing application naturally writes runtime cache/session/log data. The extension itself does not edit core files.

Apache direct requests to the module directory are denied by its own `.htaccess`, **except the non-sensitive `icon.png` used by the extensions list**; wizard assets are served by the authenticated controller. On Nginx, have the server administrator allow only `/modules/Whmcsmigrator/icon.png` and deny other direct module requests before deployment. This extension does not edit server configuration. Never deploy `.local/` development artifacts.

## Safe usage

1. Have your DBA create a dedicated account with **SELECT on the WHMCS database only**. Do not provide a root or normal WHMCS application account.
2. Allowlist the destination's actual public outbound/NAT IP on the database firewall. **Show public IP** performs an explicit HTTPS request from the FOSSBilling server to `api.ipify.org`; no database credentials/customer data are sent. **Copy IP** and **Refresh public IP** are available after lookup. PHP cURL is required for this optional feature. Verify MySQL uses the same outbound route, especially with VPNs/proxies.
3. Choose **Connection security**. Keep **Verified TLS (Recommended)** where possible; provide a provider CA override only if needed. **TLS Without Certificate Verification (Advanced)** requires explicit risk acknowledgement and actual encryption but cannot authenticate the server. **Standard MySQL Connection (No TLS)** requires explicit risk acknowledgement; no CA or TLS-specific hostname is required, and credentials/customer data may travel unencrypted. There is no automatic fallback. See [connection modes, diagnostics and HostGator instructions](docs/CONNECTIONS.md) and [CA provenance](certificates/README.md).
4. Test connection, then review every discovered table and field.
5. Review the automatic timezone evidence after testing. MySQL timezone/offset and an application-setting candidate are not proof of the remote PHP runtime. When inconclusive, verify the actual WHMCS application timezone and save the optional override without resending the password. Otherwise remain Unverified and use archive mode for event history. Version defaults to **Auto-detect**; a manually verified 8.x fallback never overrides an incompatible detected version. Native event adapters currently require a UTC-configured destination; the extension never changes global timezone settings.
6. Save mappings, analyze selected tables, choose the intended execution mode and create its dry run.
7. Explicitly resume the paused preview. Review all exceptions.
8. If the supported scope is appropriate, authorize the run with a verified backup and `PRESERVE HISTORY`. Historical financial mode additionally requires confirmation that the destination is private and otherwise idle.
9. Explicitly resume. Extraction, credit publication and financial assessment all checkpoint across requests. Browser closure stops new scheduling; reopen, select the persisted run and resume.
10. Inspect archives, credit results and financial exceptions, then export reports. Never describe archive-only records as native imports or snapshot totals as final cutover reconciliation.

### Modes

| Mode | Destination writes |
|---|---|
| Archive | Extension-owned preserved history only |
| Native references/history | Supported clients/catalog/server metadata/closed support/email history; no native financial/service orders |
| Historical native | Also attempts reviewed inactive hosting, eligible historical invoices/payments and complete credit aggregates; unsupported records remain explicit failures |
| Dry run | Extension-owned preview state only; uses the selected target mode's eligibility checks |

Do not connect this preview to real customer data until it has been reviewed. Prefer a redacted, static WHMCS snapshot first. Exact preflight counts/row reads on large source tables can consume resources even though they do not write; schedule them with the production DBA.

## Essential limitations

- A live database changes across requests. High-water IDs are bounded, but no globally consistent source snapshot exists across HTTP batches.
- Row deletions and count mismatches require manual review. Updates to a source row behind a completed cursor require a new run.
- Direct schema-wide SELECT grants are required. Role-based/table-only/column-only grants and global SELECT are refused conservatively.
- Read-only database grants do not protect confidentiality on the network: unverified TLS can be impersonated, and standard MySQL can expose credentials/customer data in transit. Prefer verified TLS or an independently secured tunnel.
- Database-only timezone inspection cannot prove PHP/configuration.php/hooks or historical timezone changes. Unverified evidence blocks timezone-sensitive native authorizations; date-only fields stay unchanged. Existing imported timestamps are never silently rewritten.
- MySQL reads request a five-second execution limit. MariaDB-specific execution deadlines and actual network-disconnect behavior are **not verified**; PHP's PDO connection timeout is not a universal query/read deadline.
- Value pages are byte-budgeted before fetching bodies. Oversized fields/rows are rejected in the SELECT and cannot become null archive substitutes. InnoDB/repeatable-read uses stable length/value probes; READ COMMITTED or non-snapshot engines use one bounded row/page. Other isolation levels are refused, not changed.
- Destination strict SQL mode is required and checked, never enabled by this extension.
- Only single positive integer primary keys have an extractor. Unknown/custom module tables require review; the tool does not guess how to extract their configuration/secrets.
- Opaque custom-field values and selected configuration/log tables require review. No claim of complete custom-field coverage.
- Opaque product/module configuration slots are excluded from extraction because they may contain secrets. They are not used to guess package identifiers.
- Free-text redaction is heuristic, not a guarantee that every historical secret is detected. Archives are confidential and must be reviewed/protected accordingly. Known secret columns are never selected.
- Invalid UTF-8/binary values and oversized records fail explicitly; they are not silently truncated or replaced.
- Changed native records and conflicts are preserved, not merged/overwritten. There is no selective native-update/link-existing UI yet.
- Source identity is based on host, port, database and prefix. Changing host aliases or moving/cloning a database requires manual identity planning.
- Native client credentials are random, discarded and hashed using PHP's supported password hashing. No WHMCS password is extracted. No welcome/reset messages are sent. Activation/reset is a separate manual post-migration procedure.
- Products remain disabled/manual even after pricing import. Only the destination's actual base currency has native product price slots. Configurable options and ambiguous package/server mappings still require manual reconciliation.
- Active/suspended/pending hosting and all native domain orders remain blocked. Inactive source hosting statuses convert to native `canceled`; exact original statuses remain in metadata.
- Unpaid/draft invoices are unsafe because core cron expiry deletion ignores approval. Compound taxes, unknown historical currencies, unallocated/multi-invoice payments and simultaneous in/out movements remain unsupported natively.
- Eligible invoices require explicit source currency and exact line/subtotal/single-tax agreement. No invented tax/balancing lines, invoice payments or exchange rates are generated. Native invoice publication is limited to 200 lines per aggregate.
- Verified credit publication is limited to 200 movements per client. Every source movement must sum exactly to the source final balance; existing unowned destination movements or actionable unpaid invoices block publication. No opening balance is synthesized. Published native credits are real funds: use only an otherwise idle private destination.
- Snapshots compare extracted source facts with native records at the recorded check time, not with a globally consistent live WHMCS snapshot. Outstanding debt/allocation reconstruction remains unverified; inferred currency bases are disclosed. Later destination changes require another assessment.
- Closed support history imports preserve staff names as legacy text, not fabricated staff IDs. Native email bodies are safe plain text; original rich content is preserved privately subject to redaction.
- Attachment references are metadata only; no remote filesystem access or verified file transfer occurs.
- There is no destructive automatic rollback or cleanup. Uninstall retains archives, mappings and business records.

## Tests

See [tests and verification](docs/TESTING.md), [compatibility](docs/COMPATIBILITY.md), [architecture](docs/ARCHITECTURE.md) and [operations](docs/OPERATIONS.md).

No live WHMCS credentials or data are bundled. `.local/`, test databases, generated reports and runtime artifacts must never be published.

## Release status

Manifest version `0.2` denotes this **experimental development preview**, not completion of the requested production migration extension. Publication as a production-ready release must wait for the remaining acceptance criteria and an independently reviewed automation-isolation strategy. See [0.2 release notes](docs/RELEASE-0.2.md).
