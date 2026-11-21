# Architecture and safety boundaries

## Verified target

The local authoritative installation declares FOSSBilling 0.8.9. It uses a mixed Doctrine ORM/DBAL and legacy RedBean model. The extension follows its `Box\Mod\Whmcstofossbilling` namespace, manifest discovery, `Service::install()`, admin controller/navigation and admin API conventions.

`vendor/composer/autoload_psr4.php` already maps `Box\Mod\` to `modules/`. No autoloader or dependency changes are required.

The local installed requirements demand PHP 8.3+. Laragon Apache configuration points at PHP 8.4.28, and that executable was used for tests. The shell's default PHP is a different installation without PDO MySQL.

## Layers

| Directory | Responsibility |
|---|---|
| `Controller/` | Authorized admin page and allowlisted JS/CSS delivery |
| `Api/` | Identity, endpoint permissions, POST, CSRF, origin checks and sanitized errors |
| `Source/` | Explicit connection-security policy, sanitized diagnostics/TLS preflight, administrator-selected timezone policy, privilege verification, fixed reads and keyset extraction |
| `Mapping/` | Versioned field-disposition registry and allowlisted text transforms |
| `Security/` | Safe errors, conservative sensitive-data policy and AES-256-GCM credential vault |
| `Installation/` | Idempotent extension-only DDL |
| `Destination/` | Validated historical aggregate persistence, products/pricing/servers, inactive hosting, eligible invoices/payments/credits and closed history |
| `Archive/` | Redacted structured evidence, historical dates, relationship hints and prior revisions |
| `Migration/` | Plan, durable cursors, serialization, bounded batches and retries |
| `Finance/` | Decimal arithmetic and resumable extracted-baseline/native assessment; not complete debt/cutover reconciliation |
| `Report/` | Streaming safe count/disposition exports |

## Extension tables

All names start with `whmcstofossbilling_`:

- `source`: connection fingerprint, GCM-encrypted options, schema and mapping profile.
- `analysis`: per-table count/high-water key and schema checksum; analysis expires after an hour.
- `run`: immutable schema/mapping/plan snapshot, mode, authorization metadata and status.
- `cursor`: table progress, source high-water key and mutually exclusive result counts.
- `identity`: cross-run source/destination identity and source/destination checksums.
- `archive`: latest sanitized source payload, dates, relationship hints and exclusions.
- `archive_revision`: previous payload/exclusions when sanitized history changes.
- `attempt`: durable per-record disposition and safe failure diagnostic.
- `batch`: committed request response replay.
- `price`: independent currency price matrices and whether the native base-currency slots were populated.
- `archive_link`: indexed current-archive relationships; `legacy_reference`: hashed searchable invoice/ticket references.
- `snapshot`, `snapshot_link`: per-run extracted records/relationships, independent of later changes to the latest archive.
- `credit_state`, `credit_result`: resumable per-client aggregate publication/checkpoints and safe failures.
- `assessment`, `reconcile_state`, `reconcile_total`: bounded financial comparisons and transactionally maintained per-currency decimal totals.

No FOSSBilling table definition is changed. `Service::uninstall()` intentionally does not delete records.

## Transactions and recovery

Source reads happen before destination write transactions. Each logical record/aggregate commits its archive, native aggregate (if supported), identity, attempt and cursor together.

A native savepoint allows preservation of a successfully extracted archive even when the native adapter fails. Native children created by the failed aggregate roll back. The record stays failed and the run cannot claim success.

The current worker uses source-level `GET_LOCK` serialization. The database releases this connection-owned lock on disconnect, so persistent lease renewal/fencing is not needed for this single-connection implementation. All code performing a batch/control operation uses the same source lock. Separate sources can progress independently.

Successful per-record transactions survive a killed request. A retry resumes from the committed cursor. If a response is lost before its final request-result marker commits, replay may process subsequent records from the durable cursor; it cannot duplicate committed identities. The implementation guarantees record-level idempotency, not exactly-once HTTP response delivery.

Each request reads at most 100 rows (UI default 50). After five seconds of local work the worker stops before the next row. A slow individual database/network operation is not forcibly interruptible by this local timer. MySQL query hints and short connection timeouts are additional defenses, not a proven bound on every network failure.

Extraction probes lengths without retrieving sensitive columns, limits each value page by a conservative PHP-memory byte budget, and evaluates oversized-row/field guards in the source SELECT. Oversized contents never become fabricated null archive data; their source IDs are retained as explicit failures. A short byte-limited page is not treated as end-of-table.

InnoDB/repeatable-read permits a stable length/value probe within one short read-only request. READ COMMITTED or non-snapshot engines use one bounded row per page. Other isolation levels are refused rather than changed. Schema inspection records the engine; old stored schema profiles therefore need a fresh connection/schema review after this upgrade.

Destination strict SQL mode is required; it is checked, never changed. Schema/plan operations refuse to take ownership of another transaction. Aggregate column definitions are cached only within a persistence instance after the destination-schema fingerprint check.

Products, add-on links, invoice headers/lines and client credit movements have aggregate/component identities and checksums. Price writes refresh only verified module-owned component snapshots. A manually changed native price cannot be silently adopted.

Only one unfinished non-preview run per source is allowed. Per-run snapshots additionally protect projection/reconciliation inputs from later runs overwriting the latest searchable archive.

After extraction, historical mode publishes at most five client credit aggregates/request, then assesses at most 50 financial records/request. Invoices and client credit aggregates are limited to 200 lines/movements; larger cases fail explicitly rather than timing out in an unbounded publication request. Actual query/network timing at production volume is still unverified.

Successful retries pause the run and rewind its assessment cursor; an explicit Resume refreshes financial results. Reassessment subtracts the prior contribution before adding the new one, preventing duplicate totals. Source and actual native currencies are compared before aggregation; a USD amount cannot be silently added to a EUR bucket.

Pause/cancel cannot undo an already committed record. An already running request may finish before control acquires the source lock. Failed-record retries are explicit, five records per request, at most five total attempts per record.

## Automation blocker

Core `modules/Cron/Service.php` invokes credit application, paid-item activation, invoice generation/reminders, service suspension/cancellation, ticket auto-close and email sending. `modules/Order/Repository/OrderRepository.php` selects expired active orders without a migration exclusion. `modules/Servicedomain/Service.php::onBeforeAdminCronRun()` attempts registrar synchronization.

An extension-owned quarantine flag is not consulted by these core selectors. A before-cron listener cannot be assumed to run before all other listeners. No unverified hook-based “suppression” is claimed.

The implementation now has a reviewed **non-operational historical subset**, not a global quarantine:

- Inactive hosting becomes native canceled orders with the exact source status in metadata; actual expiration/renewal/unpaid-cleanup selectors exclude them.
- Eligible invoices are already paid/canceled/refunded, unapproved, with custom/void/executed/charged items. Actual core paid-item/credit-payment/expiry selectors exclude them.
- Native historical transactions are processed and have no operational gateway; actual received/processing selectors exclude them.
- Credit aggregates require complete source reconciliation and no actionable destination unpaid invoice or unowned native movement.
- Only closed tickets and email delivery history are published, without lifecycle APIs or queue writes.

Unpaid invoices remain blocked because `findUnpaidOlderThan()` does not check approval. Active/suspended/pending hosting, every domain order and open-ticket workflows remain blocked. No dates/statuses are falsified to hide those records from selectors. No global settings, cron files or scheduler jobs are changed.

Historical publication requires a private, otherwise idle destination. Tests establish behavior for the supported core subset, not arbitrary third-party hooks, concurrent external writers or manual future activation. Enabling products, entering credentials or reactivating orders remains a separate administrator responsibility.

## Financial representation

The observed destination schema uses DOUBLE(18,2) for order/item prices and several invoice amounts, DECIMAL(18,2) for product prices and balances, one invoice-level tax rate, and one invoice reference per native transaction.

Exact source decimal strings are retained in snapshots/archives. Native invoices require exact single-tax/subtotal agreement and explicit historical currency. No balancing invoice lines or invented exchange rates are generated. Every stored DOUBLE monetary field used by an imported invoice/service is checked against its exact source value after persistence; a changed value rolls back its aggregate.

Processed native payments preserve dates/references and receipt/refund amounts. Fees remain archived because the native transaction schema has no fee column. Native credits are only original movements whose sum equals the source closing balance; unmatched history creates no partial postings/opening adjustments.

Assessment compares extracted invoice totals, payments/refunds/fees, credit movements and final balances with actual native records. Native fee absence, missing records, unsupported cases, inferred currency bases and drift are explicit exceptions. Complete outstanding balances, allocation/FX semantics and live cutover correctness are not claimed.

Event timestamps require a confirmed IANA source timezone and a UTC destination. Ambiguous/nonexistent daylight-saving local times fail; source calendar dates retain their original day. Per-run timezone/capability snapshots prevent an old authorization from silently changing interpretation after an upgrade.

## Source security

The source account must have a direct database-wide SELECT grant and no write, EXECUTE, FILE, grant-option or role/proxy privileges. Global SELECT is rejected. Inspection never attempts a source write to test permissions.

The source PDO interface exposes only fixed metadata/WHMCS reads and a read-only transaction. Identifiers are validated independently of prepared values. Views are excluded. Configuration values are not read wholesale: only the WHMCS version setting is queried. Generic configuration archives remain excluded.

Standard MySQL without TLS is the default and requires explicit acknowledgement. Explicitly acknowledged unverified TLS still requires actual encryption; verified TLS checks the certificate chain/hostname. Credential-free TLS preflight and actual PDO cipher checks prevent secure modes silently downgrading. Warnings/driver errors are classified into fixed messages, never echoed. Only the WHMCS source connection is affected.

The WHMCS application timezone is selected by the administrator in an IANA dropdown defaulting to America/Chicago; no timezone SELECTs, remote PHP execution or SET commands are used. An empty selection stays Unverified and blocks timezone-sensitive native records. No FOSSBilling/UTC default or offset-to-IANA inference is used. Selections update only encrypted profile metadata; run plans freeze the selection and security mode. DATETIME client registration uses the shared event/DST validator instead of assuming UTC.

Credentials use authenticated encryption derived from the existing installation secret with a module-specific HKDF context. They are never returned by an API. No plaintext credential files are created. Admin APIs require a browser session CSRF token even for read-only operations.

Archive free text is rendered using `textContent`, not HTML. It may still contain confidential personal information or unrecognized secrets; heuristic redaction is not complete data-loss prevention.
