# Tests and verification

## Commands

Run these from the FOSSBilling root using the PHP executable that serves the application:

```powershell
$php = 'D:\Laragon\bin\php\php-8.4.28-Win32-vs17-x64\php.exe'
& $php modules/Whmcstofossbilling/tests/unit.php
& $php modules/Whmcstofossbilling/tests/grants.php
& $php modules/Whmcstofossbilling/tests/security.php
& $php modules/Whmcstofossbilling/tests/render.php
& $php modules/Whmcstofossbilling/tests/integration.php --disposable-databases
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

## Disposable TLS / connection-policy server

```powershell
$env:WM_MYSQLD = 'D:\Laragon\bin\mysql\mysql8.0.31\bin\mysqld.exe'
& $php modules/Whmcstofossbilling/tests/connection.php --disposable-server
```

This separate suite initializes its **own** MySQL 8 datadir on a randomly allocated
loopback port below `.local/`. It creates a temporary CA/key/certificate with an IP
SAN, starts only that fixture process with its own TLS/query-log settings, then
stops it and removes its exact fixture tree. It never reads application DBA
credentials, changes the installed server's configuration or accepts live WHMCS
connection details.

Actual PDO tests cover:

- Standard mode with an unreadable/irrelevant CA override and no TLS negotiated.
- Verified TLS with a valid custom CA/IP identity.
- Encrypted unverified TLS despite a server CA absent from the public trust store.
- Rejected untrusted CA and trusted-CA/hostname mismatch, without downgrading.
- Authentication, missing database, missing SELECT and overprivileged-account errors.
- Timezone-candidate/offset SELECTs without selecting unrelated secret settings.
- Unchanged source contents and a MySQL general-query-log audit of source-reader
  statements: SELECT/SHOW safety metadata, READ ONLY start and rollback only.
- Fixture restart with TLS disabled: both TLS modes refuse before sending
  credentials, while explicitly selected standard mode still works.
- Real cPanel-style escaped database SELECT grants, host-specific accounts,
  sanitized privilege-scope reporting and fixture-only partial-revokes startup
  semantics. Stored backslashes are never blindly stripped in literal-name mode.

The private fixture's DBA creates the synthetic schema/users and shuts down only
that fixture server. Those setup/cleanup operations are not source-reader queries.

## Grant-validation regression suite

`tests/grants.php` uses no database/network connection. It covers cPanel escaped
underscores, quoted/doubled identifiers, account host forms, whitespace/case,
MySQL/ANSI and MariaDB authentication/resource-clause syntax, column/table scope,
scope-pattern/partial-revoke semantics, forbidden privileges/delegation and
privacy of success/failure messages. Controlled PDO fixtures verify the preferred
`SHOW GRANTS FOR CURRENT_USER()` query, syntax-only fallback, denied/empty
inspection and unavailable optional metadata. Roles and ambiguous/restricted
grants still fail closed instead of being reported as a missing database grant.
MariaDB syntax is tested as fixture text; an actual MariaDB server was not run.

## Browser harness

Node/Playwright may be installed as development-only tools under `.local/`, with its npm cache also under `.local/`. No root application dependencies are modified.

```powershell
$env:PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD = '1'
npm --prefix modules/Whmcstofossbilling/.local/browser --cache modules/Whmcstofossbilling/.local/npm-cache install playwright --ignore-scripts --no-audit --no-fund
$env:WM_PHP = $php
$env:WM_CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe'
node modules/Whmcstofossbilling/tests/browser.cjs
```

The harness uses a synthetic API, the real extension Twig template/JS/CSS, the installed admin stylesheets and a minimal test-only layout. It verifies the standard no-TLS default plus all three explicitly selected security modes, required risk confirmations, conditional CA fields, actual-state success display in dark green, the required WHMCS timezone dropdown defaulting to America/Chicago and submitted with the connection test, and no separate timezone-inspection/override request. It also covers the existing IP controls, mapping/exclusions, preview/start separation, a missing-`crypto.randomUUID` fallback that still submits a valid 32-hex batch idempotency key, the opt-in invalid-date policy, a top percentage bar that tracks scanned/expected records across steps, schema-upgrade confirmation, legacy search, financial discrepancies/missing values, safe text rendering, no browser storage and desktop/mobile layout. API responses are synthetic, not real admin writes, MySQL handshakes or external lookups. Clipboard wiring does not change the user's clipboard.

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

- No credentialed live WHMCS database connection or production migration was tested. Separate administrator-requested TCP/TLS diagnostics sent no username, password or SQL.
- No successful verified real-provider remote-TLS connection, real firewall outage, process-kill timeout, production-volume load or MariaDB compatibility test was performed. Disposable local server TLS/plaintext connections and fixture-only server restart are tested.
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
& $php modules/Whmcstofossbilling/tests/integrity.php
```

The checker compares every baseline file and detects new files outside the module. Only administrator-approved `data/cache/` and `data/log/` runtime changes are separated from unauthorized changes. Results are written inside `.local/`.

Without a pre-development baseline, the script refuses to claim unchanged files. Git-based verification of FOSSBilling itself is unavailable because this installation is not a Git repository.

## Latest local verification — 2026-11-21

- PHP syntax checks and JavaScript syntax checks passed.
- Current local changes replacing the 0.2 artifact: grant syntax/inspection 77 assertions; unit 98; endpoint security 28; render/module discovery 17; disposable migration integration 196; disposable TLS/connection server 20 — 436 assertions total.
- All 46 PHP files and both JavaScript files pass syntax checks. Connection policy, actual encryption/identity, cPanel grant recognition, query-log read-only enforcement, administrator-selected timezone snapshots, client DATETIME conversion/date-only preservation, strict-versus-opt-in invalid-date handling, module rename discovery and legacy extension-table migration pass.
- Expanded synthetic browser tests passed with the renamed admin route/API path, including the missing-`crypto.randomUUID` fallback, the opt-in invalid-date policy and the top scanned/expected percentage bar, as did the actual unauthenticated local login gate. Authenticated real-admin end-to-end verification remains pending.
- Added disposable regressions for native dependency expansion, named missing/stale/schema-mismatched counts, refreshing the expanded selection, the hard planner rejection for direct callers, an actual fixture schema-change refusal with a named table/recovery action, and unchanged synthetic source contents. Security checks confirm both preparation and per-table refresh still require configure permission.
- Expanded the synthetic browser fixture to 43 tables: three desktop columns, two tablet columns, one mobile column, long-name wrapping, search/empty states without selection changes, automatic dependency analysis, schema-change/pending-table diagnostics, and the exact authorized-run button's success/failure scroll-to-top are verified. Authorized-run responses are entirely synthetic and schedule no batches. Preview summaries explicitly state that history has not been saved. Desktop/mobile review screenshots are retained under `.local/browser-artifacts/`.
- Added browser regressions for the broadest supported historical default and every plain-English mode description; a pending connection spinner/disabled button/busy form and cleanup on success, database failure and controlled timeout; authentication-code/401/CSRF/non-JSON failures distinct from WHMCS/IP errors; redirect and cross-origin refusal; collapsed pending-table details; invalid-form refusal; and immediate scrolling while mapping preparation is deliberately held pending. Nested scroll tests use the actual admin content class hierarchy with a controlled scrollable wrapper, not an authenticated real-admin session.
- Controller fixtures verify that same-origin API paths preserve a `/billing/` installation subdirectory and root installs without a canonical hostname/scheme or token in the URL, while retaining admin/view authorization. The real anonymous local analysis endpoint also returns `Authentication Failed`/201 before any source access. Why this user's logged-in session was rejected remains unverified; expired/revoked sessions still require signing in again. Core session/fingerprint/CSRF behaviour was not changed.
- Integrity checked 8,100 baseline files but **did not pass**: `config.php` differs and `config.old.php` exists outside the extension. Both were left untouched; their origin is not established by the checker. Approved cache/log changes are separately listed in `.local/integrity-result.json`. Do not describe this result as zero external changes or reset the baseline to conceal the differences.
- Bundled CA certificates parse as real X.509 certificates, and the downloaded file SHA-256 matches the HTTPS upstream checksum. A separate explicitly requested CLI public-IP lookup succeeded over verified HTTPS; this does not prove remote MySQL TLS compatibility.
- Local HTTP checks: supplied public icon returns 200/image/png; direct manifest, CA bundle and private baseline requests return 403.
- No live WHMCS login/import was performed. Publication is an explicitly authorized experimental prerelease, not production acceptance.
