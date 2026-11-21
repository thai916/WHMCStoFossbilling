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
