# WHMCStoFossbilling — 0.2 development preview

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
- Three source-only security modes: standard MySQL without TLS (default, explicitly acknowledged), verified TLS, or explicitly acknowledged unverified TLS. Secure failures never downgrade.
- Readable default CA selection with an included Mozilla trust bundle, safe connection diagnostics and actual encryption/identity/table-availability reporting.
- Explicit administrator-requested server-side public IPv4 lookup and copy/refresh controls. No automatic public-IP requests.
- Administrator-selected WHMCS server timezone dropdown with an America/Chicago default. No automatic timezone inspection, UTC assumption or offset-to-IANA guessing.
- WHMCS version/schema inspection, conservative sensitive-table/column exclusion.
- Per-table preflight counts in separate requests.
- Editable client text-field mappings, archive/skip dispositions, mapping validation and JSON profiles.
- Batched dry run with an explicit archive/reference/historical target: source scan, client conflict checks and supported-adapter value/eligibility validation. **Not a simulation of every insertion, aggregate or financial relationship.**
- Fresh-install/ownership checks and explicit authorization before non-preview runs; no mandatory destination backup reference.
- Two source choices: existing SELECT-only database connection, or private streamed SQL/GZIP/ZIP upload with no SQL execution or staging database.
- Durable per-record checkpoints, source-level database advisory locks, committed-request replay, pause/resume/cancel, bounded failed-record retry. Step 4 groups batches into paced server-side drive calls so FOSSBilling's shared API rate limiter is not exhausted by browser polling. Row size adapts conservatively from 500 to at most 2,000 per batch using measured elapsed time and page bytes; the five-second local work cap and 8 MiB source-page budget remain hard guards.
- Cross-run identity mappings and unchanged-record skipping. Changed native records are **not** automatically updated. If the FOSSBilling admin session expires mid-run, reopening the wizard finds the newest non-dry `running`/`paused` run, opens Step 4 with a Resume/Take-over prompt and schedules nothing automatically.
- Imported hosting orders set `group_master=1` and eligible imported invoices set `approved=1`, so core client/admin order and invoice lists show them. A separate confirmed, run-scoped **Repair imported visibility** action fixes only those two fields on module-owned records from earlier imports; it never re-imports, deletes, renumbers or emails.
- Structured administrator-only archives, indexed relationships, original dates, disclosed exclusions/redactions and prior revisions. Per-run extracted snapshots cannot be replaced by a later run's latest archive.
- Native currencies, client groups, clients, categories and **disabled/hidden/manual products**.
- Native base-currency prices/setup fees for free, one-time, monthly, quarterly, semiannual, annual, biennial and triennial billing. Independent currency matrices are preserved separately without exchange-rate substitution.
- Native add-ons, their prices and parent-product links; inactive, credential-free server metadata.
- Explicit package-name fields and unambiguous single-server group relationships. Opaque module configuration slots are never guessed.
- Native closed tickets/opening messages/replies/private notes and redacted plain-text email history, preserving verified event dates without sending email.
- Separate historical mode: canceled/terminated/fraud hosting history as safe canceled native orders, preserving exact original status in metadata; exactly representable paid/canceled/refunded invoices and lines; processed historical payments/refunds; verified complete client credit aggregates.
- Resumable decimal-only extracted-baseline/native financial assessments, grouped by currency. Missing native records, currency conflicts, drift and unsupported fees remain exceptions. **Not complete debt/allocation or live-cutover reconciliation.**
- CSV/JSON exception exports with identities, exact failure reasons, attempt counts and actual assessment totals. Large runs stream only failed records, so a million-row scan still produces a downloadable report. Original textual invoice numbers/ticket references are searchable in the private archive.
- PHP, security, synthetic database integration, native-loader/Twig and synthetic browser tests.

## Installation

1. Use a fresh FOSSBilling installation with no unrelated business records. Previously imported records from the same migration can be resumed. If the installation must be reused, the authorization form offers an explicit irreversible overwrite that deletes every row in the checked business tables, including leftovers from failed migrations.
2. Place this directory at `modules/Whmcstofossbilling/` — exact capitalization matters on Linux.
3. Use the PHP runtime serving FOSSBilling, **PHP 8.3+**, with PDO MySQL, OpenSSL, BCMath, Mbstring and JSON. Backup uploads require 64-bit PHP; compressed backups also require zlib. ZipArchive is not required.
4. Log in at your FOSSBilling admin URL.
5. In **Extensions → Overview**, activate **WHMCStoFossbilling**.
6. Open **Extensions → WHMCStoFossbilling**.

The installation directory is `Whmcstofossbilling` because FOSSBilling lowercases module IDs and applies `ucfirst()` for directory/class lookup; the displayed module and downloadable asset are `WHMCStoFossbilling`. When upgrading from `modules/Whmcsmigrator/`, remove the old directory, install as `modules/Whmcstofossbilling/`, and activate the newly named extension. Installation renames extension-owned `whmcsmigrator_*` tables to `whmcstofossbilling_*` when the new names do not already exist; stored source credentials stay decryptable, and no core schema or business records are altered. The old extension/permission entry is not reused. Previously authorized native runs cannot silently acquire new adapters: cancel an old unfinished native run and create a newly reviewed plan.

Use **Upgrade extension tables only** if prompted. It requires explicit confirmation and configure/execute permission. Upgrading 0.1 to 0.2 adds no new table definitions beyond the rename. Retest saved connections to collect security diagnostics and confirm the WHMCS server timezone in the dropdown. New run plans freeze the selected security mode and timezone; changing either requires a newly reviewed run rather than silently changing an old authorization.

For this development installation:

```text
http://fossbill.test/admin/whmcstofossbilling
```

Adjust the scheme/admin prefix for your deployment. Activation creates only extension tables; it never connects to WHMCS or imports business records.

No Composer update, Docker, external queue or cron is required. The existing application naturally writes runtime cache/session/log data. The extension itself does not edit core files.

Apache direct requests to the module directory are denied by its own `.htaccess`, **except the non-sensitive `icon.png` used by the extensions list**; wizard assets are served by the authenticated controller. On Nginx, have the server administrator allow only `/modules/Whmcstofossbilling/icon.png` and deny other direct module requests before deployment. This extension does not edit server configuration. Do not ship development artifacts from `.local/`. Runtime uploads are created encrypted under `.local/backups/`; preserve that directory and the installation secret during upgrades while backups are still needed.

## Choose your source

The first screen shows only **Connect to WHMCS database** and **Upload WHMCS backup**.
Selecting one reveals its fields. Neither choice connects or imports automatically.
Both feed the same mapping, preview, authorization, archive and native adapters.

For a file, select one `.sql`, `.sql.gz`/`.gz`, or `.zip` containing one SQL member.
Choose the actual WHMCS application timezone and click **Upload & prepare backup**.
You never split the file yourself. Internal authenticated blocks keep uploads below
ordinary PHP single-file upload limits; pause/continue works while the page remains
open. Upload tokens are not stored in browser storage, so a reload during an
unfinished upload requires a new upload. Prepared sources are saved normally.

Files are encrypted at rest. The bounded parser reads UTF-8 table definitions and
plain INSERT VALUES tuples, builds encrypted seekable files and never executes
uploaded SQL. INSERT rows need not be in primary-key order: a checkpointed external
sort creates the encrypted key/offset index, including for dumps with MySQL 5.7
RocksDB probe comments. There is no staging database. See [SQL backup requirements and limits](docs/SQL-BACKUPS.md).

## Safe usage

Steps 1–5 below describe the database choice; the upload choice replaces them with
the file preparation above. The subsequent mapping and migration steps are shared.

1. Have your DBA create a dedicated account with **SELECT on the WHMCS database only**. Do not provide a root or normal WHMCS application account.
2. Allowlist the destination's actual public outbound/NAT IP on the database firewall. **Show public IP** performs an explicit HTTPS request from the FOSSBilling server to `api.ipify.org`; no database credentials/customer data are sent. **Copy IP** and **Refresh public IP** are available after lookup. PHP cURL is required for this optional feature. Verify MySQL uses the same outbound route, especially with VPNs/proxies.
3. Choose **Connection security**. **Standard MySQL Connection (No TLS)** is the default and requires explicit risk acknowledgement; no CA or TLS-specific hostname is required, and credentials/customer data may travel unencrypted. **Verified TLS** uses a readable CA bundle (provider override if needed). **TLS Without Certificate Verification (Advanced)** requires explicit risk acknowledgement and actual encryption but cannot authenticate the server. There is no automatic fallback. See [connection modes, diagnostics and HostGator instructions](docs/CONNECTIONS.md) and [CA provenance](certificates/README.md).
4. Test connection, then review every discovered table and field.
5. Select the **WHMCS server timezone** in the dropdown before testing. It defaults to **America/Chicago**; confirm it matches the WHMCS/PHP application timezone, including any historical timezone changes. No MySQL timezone or offset is inspected or inferred. Selecting an empty value leaves the source Unverified and blocks timezone-sensitive native records; use archive mode for event history. Version defaults to **Auto-detect**; a manually verified 8.x fallback never overrides an incompatible detected version. Native event adapters currently require a UTC-configured destination; the extension never changes global timezone settings.
6. Save mappings, analyze selected tables, choose the intended execution mode and create its dry run.
7. Explicitly resume the paused preview. Review all exceptions.
8. If the supported scope is appropriate, acknowledge the limitations and type `PRESERVE HISTORY`. The destination must be fresh or contain appropriate migration-owned records; unrelated data is never deleted to make it fresh. Historical financial mode additionally requires confirmation that the destination is private and otherwise idle.
9. Explicitly resume. Extraction, credit publication and financial assessment all checkpoint across requests. Browser closure stops new scheduling; reopen, select the persisted run and resume.
10. Inspect archives, credit results and financial exceptions, then export the JSON/CSV exception report from Step 4. The download contains every failed record for the selected run and no successful attempts. Never describe archive-only records as native imports or snapshot totals as final cutover reconciliation.

### After a session timeout

A `running` run can stall after logout, a 429, a closed browser or an expired absolute session; that state does not prove another tab is active. On the next page load the wizard appears directly on Step 4 for the newest non-dry `running`/`paused` run, with an **Unfinished import found** banner. A paused run offers **Resume this import**; a running run offers **Take over processing here** with a confirmation that another tab may still be driving it. **Choose another source** leaves the run untouched. Status watching is read-only until you explicitly resume or take over. `requires_action` runs remain selectable in Step 4 but do not trigger this banner.

### Repair imported visibility

Records imported by an earlier 0.2 build can be hidden in core lists. On Step 4, select that run, acknowledge the limited field change, type `REPAIR IMPORTED VISIBILITY` and confirm the final scoped prompt. The endpoint accepts only that exact phrase, requires the same admin POST/CSRF/execute checks as other mutating actions, and changes only `client_order.group_master` and `invoice.approved` on verified module-owned rows for the selected run. It refreshes module-owned checksums, is idempotent, and sends no notifications. Canceled/refunded invoices are visible to administrators; the core client portal only has Paid/Unpaid tabs.

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
- The selected WHMCS server timezone is administrator-provided profile metadata, not proof of PHP/configuration.php/hooks or historical timezone changes. An empty selection blocks timezone-sensitive native authorizations; date-only fields stay unchanged. Existing imported timestamps are never silently rewritten.
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
- Eligible imported invoices are marked approved only for list visibility; the core client portal still exposes only Paid/Unpaid tabs, so canceled/refunded history is administrator-visible rather than a fabricated portal status.
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
