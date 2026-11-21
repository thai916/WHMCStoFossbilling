# 0.2 — Connection bug fixes, privilege validation and timezone selection

**Experimental prerelease — not a production-complete migration tool.**

Author: **Monlam Makhampa**. This extension is **vibe coded**.
Direction: **WHMCS 8.x → FOSSBilling 0.8.9**.

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

## Upgrade / important limits

Replace the `modules/Whmcsmigrator/` directory with this release's directory.
No new tables, Composer changes, FOSSBilling core edits or global TLS changes are
required. Retest saved source connections to populate the new diagnostics.
Uninstall is not rollback; preserve backups and audit records.

All existing production blockers remain: active services, domains, unpaid/draft
invoices and unsupported financial/allocation/FX cases are not made production-safe
by these connection fixes. Do not cut over billing.

Read [connection and HostGator instructions](CONNECTIONS.md),
[testing](TESTING.md) and [remaining work](REMAINING-WORK.md).
No live WHMCS login/import was used to test this release.
