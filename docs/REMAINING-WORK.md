# Remaining work before a production release

The requested complete WHMCS 8.x migration is not finished. Do not interpret the manifest version as acceptance of the full project.

## Blocking architecture decisions

1. Extend the tested **supported-subset** isolation to active/suspended/pending services, all domains, unpaid/draft invoices and open support workflows. Existing subset protections are not a global automation quarantine.
2. Do not invent an extension-owned flag unless every relevant selector/action actually checks it.
3. If safe isolation requires changes outside this extension, stop and obtain explicit approval. No such external change has been made.
4. Decide on a stable source snapshot/cutover strategy; a multi-request live scan is not one.

## Core native migration

- **Implemented/tested:** native base-currency product cycles/setup fees, free/one-time modes, add-ons and parent links; preserved independent currency matrices; inactive credential-free server metadata; explicit package names and unambiguous server groups. Historical services with no package-name plan or assigned server publish NULL references rather than invented plan/server records; the original references remain archived and in native order metadata.
- **Still needed:** active configurable options, independently fixed currency prices in the native catalog, administrator-reviewed module-specific package-slot mappings and ambiguous server-group choices.
- **Implemented/tested:** inactive hosting service/order aggregates with usernames/IPs/dates/prices, exact original status metadata and safe native canceled state.
- **Blocked:** active/suspended/pending services and every native domain order until their operational safety is established.
- **Implemented/tested:** exactly representable non-unpaid historical invoices/lines, separate legacy numbering/search, processed payments/refunds, multiple payments per invoice, complete credit aggregates and exact per-currency snapshot assessments.
- **Implemented/tested:** an explicit opt-in linked-client currency policy for historical invoices whose source currency is NULL/empty. The original NULL remains archived, the native note warns about the inference, the UI counts every inference and lists the first 100, the full JSON/CSV report contains every inference, and amounts/tax/FX are not recalculated. Existing runs without the frozen policy still block these invoices.
- **Still needed:** unpaid/draft isolation, compound taxes/discount variants, source-stated resolution for other ambiguous/unknown currencies, complete legal billing snapshots and historical FX/income handling. The linked-client policy is not a general currency-inference mechanism.
- **Still needed:** fees in native reporting, unallocated/multi-invoice allocations, simultaneous in/out movements, large staged aggregates and complete outstanding-balance reconstruction.
- **Implemented/tested:** rejecting a mismatched credit ledger without partial posting or invented opening credit; repeated imports do not double-count balances.
- Contact/shared-user ownership and reviewed secret-aware custom-field extraction.
- **Implemented/tested:** native closed tickets/opening messages/replies/private notes, conservative priority conversion, legacy staff labels and native redacted plain-text email history without sending.
- **Still needed:** open-ticket isolation, full configurable status/author mapping and chronological staging of out-of-order replies; attachments still need a separately approved verified transfer.

## User interface and workflow

- Side-by-side mapping for every implemented native category, including reviewed relationship/value transforms.
- Secure synthetic/transformed previews without revealing secrets.
- Selective native update/link-existing conflict workflow with approval and destination change checks.
- Gateway and hosting module mapping interfaces.
- Post-migration account activation/reset and operational enablement checklist.
- Complete archive search by destination entity/relationship and revision viewing.
- Credential removal/expiry/rotation workflow.

## Financial verification

- **Implemented:** bounded extracted-baseline/native comparisons with atomic per-currency decimal totals, resumable assessment, missing-record/drift/currency conflict detection and report exports.
- Partial payments/refunds for safely imported invoices are tested. Arbitrary split allocations, complete debt reconstruction and historical exchange-rate semantics remain incomplete.
- Reconcile outstanding debt, credit movements, paid/unpaid/cancelled/refunded totals and taxes.
- Never manufacture ledger entries or adjustments to make totals match.
- Continue distinguishing archive preservation, extracted-subset assessment and live-source cutover reconciliation. Only the first two currently exist.

## Further tests

- Authenticated installation/activation/menu/API browser test in actual FOSSBilling.
- Expanded synthetic browser coverage now verifies extension-schema confirmation/cancellation, historical-target preview, exact legacy-reference search and financial discrepancy/missing-value displays. This does not replace the authenticated test above.
- Actual disposable-server verified/unverified/standard connections, valid CA/IP identity, invalid trust/name, missing TLS and sanitized authentication/access failures are tested. Real-provider verified TLS and remote firewall behavior remain unverified.
- MariaDB variants, query deadlines and required privilege visibility.
- Large-production-volume source-load and memory/request-time tests.
- Actual process kill, database disconnect, interrupted transaction and local service restart.
- Duplicate request replay, overlapping-run refusal, frozen source snapshots, reconciliation restart and aggregate price-drift tests pass. Actual simultaneous-process/connection-loss crash tests remain.
- Actual core paid-item/transaction/credit-payment/unpaid-cleanup selectors and batch methods, plus order expiration/renewal/unpaid-cleanup repositories, have been exercised against supported synthetic native records with no operational actions. This does not prove safety for blocked categories or arbitrary third-party hooks.
- Actual disposable MySQL query-log audit verifies only SELECT/SHOW safety metadata, READ ONLY transaction start and rollback for the source reader; no modifying statements/SET. Independent review and auditing on other supported server/client combinations remain pending.
- Resolve the latest baseline integrity differences (`config.php` modified, `config.old.php` added) with the installation owner. Their origin is unverified; leave them intact and do not claim a clean baseline.

## Publication gate

The user subsequently explicitly requested committing and creating public repository `thai916/Whmcstofossbilling`. Publication is permitted as an **experimental development preview**, not as the originally requested production-complete migration. Tag/release `0.1` must be marked as a prerelease and retain the incomplete-scope warning. See [release notes](RELEASE-0.1.md). Production acceptance criteria above remain unmet regardless of repository/release existence.

The overwritten 0.2 connection-bug-fix artifact is likewise explicitly authorized as an experimental prerelease. Its standard-by-default TLS policy, cPanel grant recognition and administrator-selected timezone do not resolve the production blockers above. Later local 0.2 source changes add nullable historical plan/server references, streamed failed-record exception exports and an opt-in linked-client currency policy for NULL-currency invoices; none of these makes the blocked categories production-safe. See [0.2 notes](RELEASE-0.2.md).
