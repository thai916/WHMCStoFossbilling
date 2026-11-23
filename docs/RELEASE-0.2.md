# 0.2 — WHMCStoFossbilling experimental migration adapter

**Experimental prerelease — not a production-complete migration tool.**

Author: **Monlam Makhampa**. This extension is **vibe coded**.
Direction: **WHMCS 8.x → FOSSBilling 0.8.9**.

## Module rename

The extension is now displayed as **WHMCStoFossbilling** and published as
`WHMCStoFossbilling-0.2.zip`. FOSSBilling lowercases module IDs and applies
`ucfirst()` for directory and class lookup, so the discovery-safe installation
directory is `modules/Whmcstofossbilling/` on case-sensitive filesystems. The
admin URL is `/admin/whmcstofossbilling`. Existing extension-owned
`whmcsmigrator_*` tables are renamed to `whmcstofossbilling_*` on installation;
stored source credentials remain decryptable. The old extension/permission entry
is not reused: activate the newly named module and review its permissions.

## Connection bug fixes

- Three source-only modes: explicitly acknowledged standard MySQL without TLS
  (default), verified TLS, and explicitly acknowledged unverified TLS.
- cPanel database-wide SELECT grants with escaped identifiers, quoted names and
  host-specific accounts are recognized. Grant inspection prefers
  `SHOW GRANTS FOR CURRENT_USER()` and reports sanitized privilege scopes without
  weakening write/delegation restrictions. Inconclusive inspection is reported
  distinctly from missing privileges.
- Certificate fields are optional overrides in verified mode and hidden in other
  modes. Standard MySQL does not require a CA or TLS-specific hostname.
- No automatic downgrade. TLS preflight distinguishes certificate validation from
  negotiation before sending credentials; PDO encryption is checked independently.
- Fixed, sanitized diagnostics for network, authentication, database/access and
  TLS problems. Success shows the actual encryption/identity state and table access.
- SELECT-only grants, read-only transactions, secret exclusion, encrypted
  credential storage and per-endpoint permission/CSRF protection are retained.

## WHMCS server timezone

- A required administrator-facing IANA dropdown replaces automatic timezone
  inspection. It defaults to **America/Chicago** and is saved with the connection
  profile.
- No MySQL session/server timezone, UTC-offset or application-setting candidate is
  queried; no offset-to-IANA guessing or implicit UTC/FOSSBilling default.
- An empty selection remains Unverified and blocks timezone-sensitive native runs.
  Remote PHP runtime/history cannot be proved through database access.
- Native timestamp-sensitive runs block while unresolved; date-only fields are
  unchanged. Client DATETIME registrations use the same selected timezone/DST rules
  as other historical event adapters.
- Run plans snapshot security mode and the selected timezone. Changing the source
  profile cannot silently weaken an existing run.
- Successful connection messages render in dark green.

## Batch scheduling

- Resume no longer depends on `crypto.randomUUID`, which is unavailable on
  non-secure local origins such as `http://fossbill.test`. A Web Crypto fallback
  keeps the 32-character lowercase hex idempotency key that prevents duplicate
  batch processing.
- Step 4 no longer sends a status request after every 50-record batch. Each
  bounded drive request processes up to four idempotent server-side batches
  within a 15-second server budget and returns the full run status, then the
  wizard waits about eight seconds before the next call. This stays under
  FOSSBilling's shared hourly API limiter, which counts every authenticated
  admin request twice. A 429 response stops polling, shows the server's
  `Retry-After` wait and never retries automatically; core rate-limit settings
  are not changed.
- Row sizing now adapts from a conservative 500 rows using measured elapsed time
  and source-page bytes. Fast, small batches grow toward a hard maximum of
  2,000; batches near the five-second work cap or 8 MiB page budget shrink, and
  empty batches back off. The last measured decision persists across drive
  calls, while replaying an identical drive request still returns its original
  result. The five-second per-batch work cap, 8 MiB page budget, source locks,
  cursors and 32-hex idempotency guards are unchanged. This is not a measured
  production throughput guarantee.

## Imported visibility and repair

- Native hosting imports now set `client_order.group_master=1`; core order lists
  default to `hide_addons=1` and otherwise filter imported services out.
- Eligible historical invoices now set `invoice.approved=1`; the core client
  invoice API forces `approved=true`. Only paid/canceled/refunded invoices are
  published, so approval grants list visibility without creating a payable
  state. Canceled/refunded history remains administrator-visible because the
  client portal only has Paid/Unpaid tabs.
- Imports from earlier 0.2 builds can be repaired from Step 4 with the exact
  phrase `REPAIR IMPORTED VISIBILITY`. The confirmed action is scoped to the
  selected run/source, changes only those two flags on verified module-owned
  records, refreshes module-owned checksums and is idempotent. It does not
  re-import, delete, renumber, notify, change statuses or touch core schema.

## Session-timeout recovery

- On page load, the newest non-dry `running`/`paused` run opens Step 4 directly
  with an **Unfinished import found** banner. No batch is scheduled, retried or
  repaired automatically.
- Paused work offers **Resume this import**. Work still marked `running` offers
  **Take over processing here** with an explicit confirmation because another
  tab may still be processing it; declining changes nothing.
- **Choose another source** escapes the recovery prompt without altering the
  run. `requires_action` runs remain selectable but do not trigger the banner.

## Historical dates and progress

- Invalid calendar dates such as `0000-00-00` still fail closed by default. An
  administrator can explicitly opt in to native client registration dates using
  `1900-01-01 00:00:00`. The exact source value remains in the archive and every
  substitution is listed under **Date substitutions** and in the JSON/CSV report.
  Daylight-saving gaps, ambiguous timestamps and unverified timezones are never
  substituted. Run plans are immutable, so an existing run cannot be switched to
  this policy; create a newly reviewed run.
- A top progress bar shows scanned/expected records and a percentage on every
  step. It measures scanning, not migration completion; exceptions and archive-only
  records stay visible in the run report.

## Review, automatic analysis and preservation visibility

- Review & preview uses a searchable three-column table list on desktop, two on
  tablets and one on mobile. Filtering never changes the selection; eligible
  tables still start selected. Excluded and review-required tables remain visible.
- Before creating either kind of run, the wizard asks the server to expand the
  selected mode's dependencies and identify missing, stale (one hour) or
  schema-mismatched counts. It automatically refreshes only those tables through
  the existing read-only analysis endpoint, one request per table. Both preparation
  and analysis retain the configure permission; run creation retains execute.
- The planner still enforces freshness and schema checks. Direct API callers must
  prepare/analyze before creating a run; preparation is not permission to import.
  Schema-change errors name the table being analyzed and the wizard lists the
  remaining counts needing refresh. Retest the connection and review a new plan.
- Successful and failed run creation scroll to the page top. Successful runs
  show their progress immediately and stay paused until explicitly resumed.
- The less cluttered interface separates selection, preview and authorization.
  Run summaries distinguish native imports, archive-only outcomes and exceptions;
  dry-run summaries explicitly say that history has not been saved.

The priority is maximum safe preservation of client history, not just native
publication. Supported records are archived before native adapters run, so a
native adapter failure does not discard the already-preserved source evidence.
This update does not add new extractors or claim complete history coverage:
secrets remain excluded/redacted, and custom-field values, attachments, unknown
tables, binary/oversized records and other unsupported cases still require review.
Archive-only history is private legacy evidence, not a native client-portal view.

## Session diagnostics, immediate scrolling and clearer choices

- API URLs are origin-relative and retain an installation subdirectory; browser
  requests retain same-origin cookies and refuse redirects or cross-origin API
  destinations rather than sending security tokens elsewhere.
- FOSSBilling authentication errors (201–206), HTTP 401 without an error envelope,
  invalid CSRF tokens and non-JSON/login responses have actionable FOSSBilling
  session guidance, separate from WHMCS database authentication and IP restrictions.
  Analysis stops immediately; pending table names appear in a collapsed list.
  Expired/revoked sessions still require signing in again and reloading the wizard.
  No authentication, CSRF, fingerprint or permission check is bypassed.
- Run creation scrolls immediately, before mapping/count requests finish, and
  scrolls both the document and scrollable admin ancestors. Required-field
  failures also show a top-of-page explanation without submitting a run.
- Mode names and live plain-English explanations distinguish ordinary FOSSBilling
  records from the private archive. **Import the most supported client history**
  (`historical_native`) is now the wizard default. It adds supported historical
  financial/inactive-hosting publication to client/catalog/closed-history imports;
  all modes archive the selected supported records. Financial authorization, UTC
  destination, private/idle-destination and eligibility restrictions still apply.
  Selecting this default does not authorize or resume a run.
- Testing a connection shows a visible spinner and “Testing connection…” label,
  disables repeated submissions, announces the busy form and clears its password
  and loading state on success, failure or timeout. Reduced motion is respected.

The exact cause of an individual administrator session being rejected has not
been established through a real authenticated admin session. The local anonymous
API is verified to return `Authentication Failed`/201 before source access.

## Continue saved work and show preparation progress

- Creating an authorized run first checks for an unfinished non-preview run for
  that source. The wizard opens it automatically before mapping saves or source
  count reads, even when new authorization fields are empty. Saved plans and
  authorizations remain unchanged; mode/table/date-policy/mapping/schema/timezone
  differences are explained. Paused runs still need explicit Resume processing.
- A running existing run is watched with read-only status requests about every
  10 seconds while its progress screen is open. This does not schedule batches
  or prove another worker is active. Use Resume processing, or the startup
  take-over prompt, explicitly if processing needs to continue in this tab.
  Hidden tabs skip status reads; navigation, processing in this tab and errors
  stop observation.
- Cancellation/replacement is an explicit confirmed action. Previously imported
  records and preserved evidence are never deleted or rolled back unless the
  administrator separately supplies the irreversible overwrite confirmation in
  the reviewed form. The new run uses the current reviewed form and still passes
  the normal authorization and planner gates. Changes during cancellation require
  another review.
- The source lock and overlapping-run guard remain unchanged. If another tab
  creates a run between the early lookup and creation, the wizard opens that
  existing run rather than retrying writes or cancelling it automatically.
  Cancelled/finished runs and dry-run previews are not reused as unfinished work.
- A separate preparation indicator animates settings checks and run creation.
  Table analysis shows completed tables / total and an actual percentage, not an
  elapsed-time estimate or migration-completion claim. Animation stops on failure;
  normal record-scanning progress takes over when the paused run is shown.
- Scrolling is now smooth for the document and nested admin scroll containers.
  Reduced-motion preferences disable smooth scrolling, stripes and transitions.

## Two source choices and data-only SQL backups

- The first screen contains only **Connect to WHMCS database** and **Upload WHMCS
  backup**. Relevant fields appear after selection; both paths share mappings,
  previews, authorization and the existing safe native/archive adapters.
- Accepts one large SQL, SQL.GZ/GZ or standard ZIP/ZIP64 containing one SQL file.
  Users never split files. Internal authenticated blocks provide in-page pause/
  continue and idempotent upload retries, with upload/prepare progress.
- SQL is tokenized as data, never executed. No staging database, shell command,
  Composer dependency or core-schema modification is used. Encrypted seekable
  row files feed the existing resumable engine, including native client imports.
- Row order in the dump no longer needs to match primary-key order. A bounded,
  checkpointed external sort builds an encrypted `(key, offset)` index for every
  table; duplicate or invalid keys still fail closed. The exact MySQL 5.7 RocksDB
  capability-probe comments found in the reported backup are allowed; altered
  probes and every other executable comment are rejected.
- Backups/checkpoints are authenticated and encrypted at rest. Ownership, path/
  ZIP traversal, CRC, size and syntax checks fail closed. Source identity
  is frozen into the plan; required source files cannot be removed during an
  unfinished run. Explicit cleanup retains imported/archive evidence.
- Fresh-install/ownership checks replace mandatory destination backup fields.
  Unrelated business records block authorization by default without deletion or
  alteration. Existing same-source migration-owned records can be resumed. When
  a destination must be reused, the authorization form can show per-table row
  counts and require an irreversible `OVERWRITE FOSSBILLING DATA` acknowledgement:
  every row in the checked business tables is deleted in the same transaction
  that creates the new paused run, stale identity mappings are cleared, and
  table structures/reference records are untouched. Failed-migration leftovers
  are covered because the reset is not limited to tracked records. Idle/private
  historical-finance confirmation and all native safety restrictions remain.
- SQL import supports a conservative UTF-8 CREATE/INSERT subset with positive
  integer keys, not arbitrary SQL engines or every provider's dump syntax.
  Files prepared by an earlier 0.2 build must be uploaded again because they
  lack the final sorted index. Limits are 64 GiB upload / 128 GiB expansion,
  subject to disk/server resources; real multi-gigabyte upload/throughput is
  not yet established.

Read [SQL backup requirements](SQL-BACKUPS.md). Keep the page open for unfinished
uploads and preserve `.local/backups/`, its installation path and the installation
secret during upgrades. Prepared sources/runs persist; in-progress upload tokens
are intentionally not stored in browser storage. File processing remains browser-
scheduled; compression replays to checkpoints and no speedup is promised without
benchmarking. No live import or authenticated real-admin upload was tested.

## Upgrade / important limits

Remove the old `modules/Whmcsmigrator/` directory and install this asset as
`modules/Whmcstofossbilling/`, then activate **WHMCStoFossbilling** in Extensions.
The first install renames extension-owned `whmcsmigrator_*` tables when the new
names do not already exist; it never modifies core or business tables. No Composer
changes, FOSSBilling core edits or global TLS changes are required. Retest saved source connections to confirm the selected timezone and security diagnostics.
Uninstall is not rollback; preserve backups and audit records.

All existing production blockers remain: active services, domains, unpaid/draft
invoices and unsupported financial/allocation/FX cases are not made production-safe
by these connection fixes. Do not cut over billing.

Read [connection and HostGator instructions](CONNECTIONS.md),
[testing](TESTING.md) and [remaining work](REMAINING-WORK.md).
No live WHMCS login/import was used to test this release.
