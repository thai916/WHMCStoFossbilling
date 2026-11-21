# Operations and recovery

## Permissions

Use a trusted administrator with the module's view/configure/execute/archive/export permissions. Archive access exposes historical private information. API token possession alone is insufficient: migration endpoints also require a session CSRF token and POST.

Use HTTPS for the FOSSBilling administrator interface on any non-loopback deployment. Source database TLS does not protect an unencrypted browser-to-FOSSBilling connection.

## Source credentials

Use a new, database-scoped SELECT-only account. Never use the production WHMCS application account. Provisioning that account is a DBA task; this extension never issues source GRANT/CREATE/ALTER commands.

Verified TLS is the default and validates the CA/hostname. Two weaker source-only modes require explicit administrator selection and risk acknowledgement: unverified TLS (encryption required, identity unauthenticated) and standard MySQL (TLS not requested). Only verified TLS shows certificate overrides. Provider-specific private certificates/keys must not be committed; the included public root bundle has its own provenance notice. A loopback tunnel is only as secure as its external setup. See [connection instructions](CONNECTIONS.md).

Credentials are encrypted in extension tables using the destination installation secret. Protect database backups and the installation configuration together. Changing the installation secret without a controlled key migration makes stored profiles unreadable; retest connections after a key change.

The public outbound IPv4 address is never guessed or fetched automatically. The first-page **Show public IP** / **Refresh public IP** buttons explicitly request a server-side HTTPS lookup at `api.ipify.org` (the provider sees the server's IP, not database credentials/customer data). The endpoint requires configure permission, session-bound POST/CSRF and explicit lookup confirmation. Only a fixed URL is used; redirects, unverified TLS, unbounded response bodies and caller-supplied lookup URLs are not allowed. Failed refresh clears the old IP/copy control. Verify MySQL uses the same route, or obtain its actual outbound address from your network administrator.

In verified mode, the CA field defaults to readable PHP `openssl.cafile` / `curl.cainfo` configuration, then the extension's Mozilla/curl public CA bundle. Empty input reuses the default; invalid explicit paths fail instead of falling back silently. Private/self-signed certificates require the provider CA for verification. Standard mode needs no certificate; unverified mode explicitly disables identity verification but must still encrypt. No global PHP/MySQL settings are changed and no runtime trust-bundle download or automatic security downgrade is performed.

## Run states

| State | Meaning |
|---|---|
| `paused` | No future batches authorized until Resume |
| `running` | Explicitly resumed; a browser must schedule bounded batch requests |
| `preview_finished` | Dry scan finished; no native insertion/financial success implied |
| `partial_archived` | Selected records scanned without unresolved row failures; required native migration categories remain unimplemented |
| `requires_action` | Failed rows or discovered/processed count mismatch; cannot claim success |
| `cancelled` | Future work cancelled, existing destination records retained |

There is deliberately no “complete migration” state in version 0.2.

## Supported native historical mode

The separate historical mode requires explicit confirmation of a private, otherwise idle destination. It can publish canceled/terminated/fraud hosting history, eligible paid/canceled/refunded invoices, processed payments/refunds and complete reconciled credit aggregates. It does **not** enable native active services, domain orders, unpaid/draft invoices or incompatible tax/currency/allocation cases.

Source hosting statuses are retained verbatim in archive/order metadata; the reviewed native historical state is `canceled`. Do not use native Uncancel/Activate as a migration shortcut: those are operational administrator actions.

Imported products remain disabled/manual and servers inactive/credential-free. Only explicit package names and unambiguous groups are mapped. Entering credentials or enabling products is not part of the import.

Native client credits are real funds once committed. The extension checks for actionable unpaid invoices and unknown destination movements before publication, but cannot prohibit out-of-band administrator/integration writes. Keep the destination otherwise idle. No global cron/automation setting is changed.

Event timestamps require the confirmed WHMCS IANA timezone. Calendar dates are preserved without shifting the business date. Native event adapters require a UTC destination and refuse ambiguous/nonexistent daylight-saving times; no global timezone change is made.

Test connection submits the administrator's selected WHMCS server timezone from a required IANA dropdown, defaulting to America/Chicago. No application setting or MySQL timezone/offset is inspected. An empty selection remains Unverified and blocks native timestamp-sensitive authorizations; no UTC, FOSSBilling timezone or offset-derived zone is substituted. Saved profiles can be retested and the dropdown changed without resubmitting a password, but existing runs retain their snapshots. WHMCS version defaults to auto-detection; the verified 8.x fallback never overrides a detected incompatible version.

## Interrupted requests

1. Stop scheduling requests after a network error.
2. Refresh the server-side run status.
3. Resume explicitly when ready.
4. Review any failed records separately.

Database connection loss rolls back the currently open destination transaction. Earlier committed records and cursors remain. A killed browser does not reverse committed work.

Pause/cancel waits for the source advisory lock; if a batch is still executing, the control request may ask you to retry. The browser stops scheduling immediately. There is no claim that an in-flight source read is forcibly cancelled.

## Exceptions and retries

Resolve missing native dependencies, duplicate emails, invalid dates or field-size conflicts before retrying. Do not edit source production records to satisfy the importer.

The retry button processes up to five failures per request and allows at most five total attempts per record. Mapping snapshots are immutable within a run; a changed mapping needs a new run.

Invalid client registration dates (`0000-00-00` or impossible calendar values) fail closed by default and remain in the archive. With the explicit **1900-01-01** checkbox selected when the run is created, those clients import natively with `created_at = 1900-01-01 00:00:00`; the original value stays in `whmcsmigrator_archive.original_dates` and is shown under **Date substitutions** and in the JSON/CSV report. Daylight-saving gaps, ambiguous timestamps and unverified application timezones are still hard failures. Do not edit source records to satisfy the importer. Because run plans are immutable, an existing run cannot be switched to this policy; create a new reviewed run.

Credit-publication failures are separately visible and share the bounded retry workflow. After a successful retry, the run pauses; Resume refreshes financial assessment. An old native run predating the new capability revision must be cancelled and recreated with a reviewed plan.

Changed native source records require manual review. Existing destination values are not overwritten. A source data change can add an archive revision while the corresponding native update remains blocked.

An intentionally incomplete native mapping should not be “fixed” by manually inserting arbitrary statuses or synthetic ledger adjustments.

## Backup and rollback

The authorization checkbox records the administrator's attestation; the extension does not independently restore-test a backup.

Restore an appropriate destination backup only through your normal administrator-approved recovery procedure. There is no full automatic rollback feature. Imported records may have been used or changed since creation.

Uninstall/deactivation is not a rollback and retains evidence and business records. Do not remove extension tables unless you have independently exported/backed up the audit trail.

## Archive privacy

Known secret columns are not selected. Recognizable password/token/card-like content in free text is redacted with an exclusion note. This is heuristic: do not treat archives as guaranteed secret-free.

Archive HTML is rendered as text, never injected into the DOM. Internal staff notes remain in the private archive. Ordinary CSV/JSON reports contain identifiers and dispositions, not archive payloads.

Attachment fields do not establish file transfer. Private files require a separately approved transfer and verification mechanism.

## Final cutover

**Do not perform a production billing cutover with 0.2.** Supported historical native records and extracted-baseline assessments exist, but active service/domain/unpaid-invoice isolation, complete debt/allocation/FX reconstruction and authenticated real-admin verification remain incomplete.

Assessment results are timestamped comparisons of frozen extracted facts against native records. Reports do not claim that the live WHMCS system remained unchanged during extraction. Missing native records, unsupported fees, currency conflicts and inferred currency bases remain exceptions. Reassess after later destination changes.

A complete implementation still needs a stable source snapshot or controlled final cutover and separate approval of hosting, registrar, payment, customer-access and notification activation.
