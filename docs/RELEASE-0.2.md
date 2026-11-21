# 0.2 — Connection bug fixes and timezone diagnostics

**Experimental prerelease — not a production-complete migration tool.**

Author: **Monlam Makhampa**. This extension is **vibe coded**.
Direction: **WHMCS 8.x → FOSSBilling 0.8.9**.

## Connection bug fixes

- Three source-only modes: verified TLS (default), explicitly acknowledged
  unverified TLS, and explicitly acknowledged standard MySQL without TLS.
- Certificate fields are optional overrides in verified mode and hidden in other
  modes. Standard MySQL does not require a CA or TLS-specific hostname.
- No automatic downgrade. TLS preflight distinguishes certificate validation from
  negotiation before sending credentials; PDO encryption is checked independently.
- Fixed, sanitized diagnostics for network, authentication, database/access and
  TLS problems. Success shows the actual encryption/identity state and table access.
- SELECT-only grants, read-only transactions, secret exclusion, encrypted
  credential storage and per-endpoint permission/CSRF protection are retained.

## Timezone evidence

- Automatic bounded configuration-candidate inspection and MySQL session/server
  timezone/UTC-offset SELECTs, with method/confidence displayed.
- No assumption that database timezone equals WHMCS/PHP timezone; no offset-to-IANA
  guessing or implicit UTC default.
- Optional administrator-verified IANA override only when automatic evidence is
  inconclusive. Remote PHP runtime/history cannot be proved through database access.
- Native timestamp-sensitive runs block while unresolved; date-only fields are
  unchanged. Client DATETIME registrations now use the same verified timezone/DST
  rules as other historical event adapters.
- Run plans snapshot security mode and timezone evidence. Changing the source
  profile cannot silently weaken an existing run.

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
