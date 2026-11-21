# Tests and verification

## Commands

Run these from the FOSSBilling root using the PHP executable that serves the application:

```powershell
$php = 'D:\Laragon\bin\php\php-8.4.28-Win32-vs17-x64\php.exe'
& $php modules/Whmcsmigrator/tests/unit.php
& $php modules/Whmcsmigrator/tests/security.php
& $php modules/Whmcsmigrator/tests/render.php
& $php modules/Whmcsmigrator/tests/integration.php --disposable-databases
```

The integration test:

- Requires the explicit disposable-database flag.
- Refuses a non-local destination configuration.
- Reads the installed destination's table definitions only.
- Creates randomly named `wm_test_source_*` and `wm_test_destination_*` databases.
- Creates a randomly named SELECT-only fixture account scoped to the synthetic source.
- Never accepts production WHMCS credentials.
- Cleans up only the database/account names created by the current process.
- Never writes business records in the configured FOSSBilling database.

It needs a local DBA account allowed to create disposable databases/users. If those permissions are not available, it must fail; do not substitute production WHMCS credentials.

## Browser harness

Node/Playwright may be installed as development-only tools under `.local/`, with its npm cache also under `.local/`. No root application dependencies are modified.

```powershell
$env:PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD = '1'
npm --prefix modules/Whmcsmigrator/.local/browser --cache modules/Whmcsmigrator/.local/npm-cache install playwright --ignore-scripts --no-audit --no-fund
$env:WM_PHP = $php
$env:WM_CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe'
node modules/Whmcsmigrator/tests/browser.cjs
```

The harness uses a synthetic API, the real extension Twig template/JS/CSS, the installed admin stylesheets and a minimal test-only layout. It verifies wizard flow, supplied PNG rendering, default CA submission, version/timezone dropdowns and explicit verification, public-IP lookup/copy/failed-refresh behavior, field filtering, disabled sensitive tables, historical-target preview/start separation, schema-upgrade confirmation/cancellation, exact legacy-reference search, financial discrepancies and missing-native-value displays, safe archive/diagnostic rendering, no local/session storage and desktop/mobile layout. IP/schema-upgrade/financial responses in this harness are synthetic, not real admin writes or external lookups. Clipboard wiring is tested without modifying the user's clipboard.

It also checks that the actual local admin page requires login when unauthenticated.

**It does not establish that activation, navigation and every API work in an authenticated real FOSSBilling admin browser session.** The native module loader separately verifies manifest/controller/service/settings discovery, and security checks exercise the API guards with controlled identities/session/permission stubs.

## Verified locally

- PHP syntax validation for extension PHP files.
- JavaScript syntax validation.
- Unit tests: validation, read-only grant policy, identifier allowlisting, authenticated credential encryption/tamper rejection, decimal precision, historical dates, sensitive-data classification, CSV safety and mapping restrictions.
- Security tests: all implemented endpoints require CSRF, POST, correct identity and endpoint permission; cross-origin requests are refused; unexpected exception text is sanitized.
- Native loader and Twig rendering/discovery.
- Disposable MySQL 8.0.31 integration: repeat installation, source schema/version detection, bad credentials/database/port, overprivileged-account rejection, source secret-column exclusion, dry-run isolation, request replay, pause/resume after service reconstruction, native client dates, disabled/manual products, unchanged-row idempotency, protected native categories, historical financial archive values, JSON export privacy, changed-source conflicts, prior archive revision, bounded retries and unchanged source digest.
- Continued native integration: base-currency cycle/setup prices, free/one-time modes, independent EUR price matrix, add-on links, inactive credential-free server metadata, explicit package/single-server association and aggregate child-price drift rejection.
- Closed ticket/opening/reply/private-note publication and redacted native email history with original dates and no email queue entries.
- Historical native mode: atomic single-tax paid invoice, original invoice/payment dates, partial receipts/multiple payments/refund, processed/no-gateway transaction state, failed compound-tax/unpaid invoices and failed payments without a native invoice.
- Complete client credit aggregate publication, no duplicate opening balance, original movement dates, exact closing balance and deliberately mismatched source history producing no partial ledger/adjustment.
- Exact per-currency extracted/native invoice/payment/refund/credit comparisons; native fee absence and missing records remain exceptions. Later-run source changes do not replace older snapshots, and assessment restart does not double-count totals.
- Historical canceled hosting service/order with original identity/IP/dates/cycle/price and exact legacy-status metadata.
- Actual FOSSBilling invoice paid-item, transaction-received, automatic-credit and old-unpaid cleanup selectors; activation/payment batches with no operational calls; actual Doctrine order expiration/renewal/unpaid-cleanup repository queries exclude imported canceled history.
- Native module loader installation into the disposable database, overlapping non-preview-run refusal, searchable original textual invoice reference and consistent CSV financial-report columns.
- Source-side oversized-value rejection, refusal to archive null extraction sentinels, adaptive byte-budget pagination with correct end-of-table handling, actual engine processing of all eligible large records, and unchanged source digests for the large fixtures.
- Synthetic Chromium desktop/mobile wizard tests and actual unauthenticated local login gate.

## Not established

- No live WHMCS connection or production migration was tested.
- No successful verified remote-TLS connection, real firewall outage, server restart, process-kill timeout, production-volume load or MariaDB compatibility test was performed.
- Supported native historical adapters and extracted-baseline assessments are tested, but **active/suspended/pending services, domains, unpaid/draft invoices, complete configurable options, arbitrary allocations, outstanding debt and live cutover reconciliation remain unverified or blocked**.
- No real attachment transfer.
- No proof of a global automation-isolation mechanism, arbitrary third-party hook safety or concurrent out-of-band destination writer safety. The historical financial mode explicitly requires an otherwise idle private destination.
- No authenticated real-admin activation/menu/API end-to-end test without an administrator session.

These missing checks must not be reported as passing. The original production-complete acceptance criteria remain unmet.

## Financial test fixture

The disposable fixture includes a safely representable EUR invoice totaling 22.00, receipts of 10.00 and 12.00, and a 2.00 partial refund. USD invoices totaling 165.00 deliberately exercise unsupported compound-tax/unpaid cases; their absent native amounts remain missing, not zero-converted totals.

One client has a correct credit ledger of +50.00 and -25.00 against a 25.00 source balance. Another has a deliberately incomplete +4.00 history against a 5.00 source balance: nothing is posted for that client. The aggregate USD closing-balance comparison therefore truthfully shows source 30.00 versus native 25.00.

These are synthetic assertions, not evidence of a live-source or whole-billing-system reconciliation.

## File-integrity check

The local pre-development SHA-256 baseline is private under `.local/core-baseline.json` and is never committed. Run:

```powershell
& $php modules/Whmcsmigrator/tests/integrity.php
```

The checker compares every baseline file and detects new files outside the module. Only administrator-approved `data/cache/` and `data/log/` runtime changes are separated from unauthorized changes. Results are written inside `.local/`.

Without a pre-development baseline, the script refuses to claim unchanged files. Git-based verification of FOSSBilling itself is unavailable because this installation is not a Git repository.

## Latest local verification — 2026-11-21

- PHP syntax checks and JavaScript syntax checks passed.
- Unit: 71 assertions; endpoint security: 23; render/module discovery: 9; disposable MySQL integration: 167.
- Expanded synthetic browser tests passed, as did the actual unauthenticated local login gate. Authenticated real-admin end-to-end verification remains pending.
- Integrity checked 8,100 baseline files but **did not pass**: `config.php` differs and `config.old.php` exists outside the extension. Both were left untouched; their origin is not established by the checker. Approved cache/log changes are separately listed in `.local/integrity-result.json`. Do not describe this result as zero external changes or reset the baseline to conceal the differences.
- Bundled CA certificates parse as real X.509 certificates, and the downloaded file SHA-256 matches the HTTPS upstream checksum. A separate explicitly requested CLI public-IP lookup succeeded over verified HTTPS; this does not prove remote MySQL TLS compatibility.
- Local HTTP checks: supplied public icon returns 200/image/png; direct manifest, CA bundle and private baseline requests return 403.
- No live WHMCS connection/import was performed. Publication is an explicitly authorized experimental prerelease, not production acceptance.
