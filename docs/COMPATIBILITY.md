# Compatibility matrix — actual 0.1 implementation

**Native means a functional destination record was written. Archive means administrator-only historical evidence, not operational equivalence.** Only the limited adapters listed as native exist. Other categories have no fake import handlers.

The WHMCS table names below are default-prefix examples. Discovery uses the configured prefix and actual columns; required base tables must be accessible.

| Source category | Destination | Actual status |
|---|---|---|
| `tblcurrencies` | `currency` | Native ISO-like three-letter code and rate; exact code may link to an existing currency without changing its rate/default. Full original row archived. |
| `tblclientgroups` | `client_group` | Native title; source settings archived. |
| `tblclients` | `client` | Native mapped identity/address/email/phone/company/tax-ID fields, currency/group relationships, historical registration date and conservative status conversion. Extra values, including original credit balance, archived. No spendable credit import. |
| `tblcontacts`, `tblusers`, `tblusers_clients` | Extension archive | No native shared-user/contact membership equivalence. Sensitive columns excluded. |
| `tblcustomfields` | Extension archive | Definitions preserved where readable. No automatic custom-slot assignment. |
| `tblcustomfieldsvalues` | None | Requires a reviewed secret-aware extractor: opaque values can contain password-type custom fields. |
| `tblservers`, server groups/membership | Native inactive servers + archive | Reviewed installed panel adapters, addresses/nameservers/port/SSL; all credential fields remain null. Group membership is indexed; only an unambiguous single-server product group is assigned natively. |
| `tblproductgroups` | `product_category` | Native title/description/order. Unsupported properties archived. |
| `tblproducts` | `product`, `product_payment`, optional hosting plan | Native identity/category/type/stock, disabled/hidden/manual; explicit package-name fields can create a referenced hosting plan. Opaque module configuration slots are excluded pending a secret-aware extractor, not guessed or blindly archived. |
| `tblpricing` | Native base-currency pricing + extension matrix | Free, one-time, monthly through triennial cycle prices/setup fees; -1 disables a cycle. Independent foreign-currency prices remain exact structured matrices, not converted or substituted into native slots. |
| Product add-ons | Native add-on products/prices/parent links + archive | Supported billing modes, setup/recurring prices and original package links; all products remain disabled/manual. |
| Configurable options | Extension archive | Definitions/selections preserved; equivalent active native option/upgrade logic remains incomplete. |
| `tblhosting` | Historical native service/order subset + archive | Historical mode supports terminated/cancelled/fraud records as native canceled orders, exact original status in metadata, original account identity/IP/server/package/dates/cycle/amount. Active/suspended/pending remain blocked. |
| `tbldomains`, related metadata | Extension archive | Domain dates/status/relationships retained; sensitive EPP/auth fields excluded. No registrar API action. |
| `tblinvoices`, `tblinvoiceitems` | Supported historical native subset + archive | Paid/canceled/refunded invoices with explicit source currency, exact line/subtotal/single-tax agreement and at most 200 lines publish atomically. Numeric legacy numbers use a separate series; text numbers remain searchable by original reference. Items are custom/void/executed/charged with no operational service task. Unpaid/draft, compound-tax and ambiguous-currency invoices remain blocked. |
| `tblaccounts` | Processed native transactions where invoice safely exists + archive | Historical dates/references/currencies/positive receipt or refund movements; multiple payments and partial refunds per invoice. No gateway adapter assigned or invoked. Fees, unallocated/multi-invoice cases and simultaneous in/out semantics remain unsupported natively. |
| `tblcredit` and client credit | Verified native aggregates + archive | At most 200 original movements/client, atomic publication, unchanged verified client identity, no unowned destination movements or actionable unpaid invoices, exact source closing-balance agreement. No invented opening balance; mismatches publish nothing. |
| Ticket departments/statuses/priorities | Native helpdesks + archive | Inactive historical helpdesk settings, explicit Closed-only policy; Low/Medium/High are deliberately converted to numeric ranks 50/100/200 (FOSSBilling uses an editable numeric priority, not these WHMCS enums). Original labels remain archived. |
| Closed tickets/replies/internal notes | Native records + archive | Original dates, ownership and chronological reply order. Legacy staff names are displayed as text, never invented native staff IDs. Internal notes are separate private records. Out-of-order replies and open workflows require further staging/isolation. |
| Ticket attachment fields | Extension metadata | References only; **no attachment files transferred**. |
| Emails | Native delivery-history records + archive | Redacted plain-text body and verified original UTC event timestamp; no queue entry or sending. Original rich content remains in the private archive. |
| Activity/admin history | Extension archive | Original actors/events retained without fabricating native staff or replaying actions. |
| Notes | Extension archive and limited client notes field | Structured timestamp/author/entity context retained where source fields exist. |
| Promotions/affiliates/quotes/billable items/tax/domain pricing/cancellations/upgrades/usage | Extension archive | Known table rows preserved, not active business configuration. |
| Gateway/configuration/security/paymethod/token tables | Excluded | No secret or dangerous configuration import. Only the version configuration value is queried separately. |
| Opaque module settings/logs/email templates | Review required | These may contain credentials inside generically named values; not automatically extracted. |
| Unknown custom tables | Review required | Discovered and listed, never silently imported or classified as complete. |
| Composite/non-integer/non-positive keys | Unsupported cursor | Requires a reviewed extractor. No offset-based silent approximation. |

## Fixed client relationships and transforms

- `currency` numeric source ID resolves through the currency identity mapping to destination `currency.code`.
- `groupid` resolves to `client.client_group_id`.
- `datecreated` becomes `created_at`, retaining the original calendar date; it is not replaced with migration time.
- Active → active; Inactive → suspended; Closed → canceled. Unknown statuses fail native conversion, with the original retained.
- `firstname/lastname/companyname/address1/address2/city/state/postcode/country/phonenumber/tax_id/notes/email` have reviewed editable client text targets.
- Text transforms: keep original, trim, lowercase+trim. Arbitrary SQL/PHP expressions are not allowed.
- Source keys and selected financial/service fields cannot be skipped through mappings.

## Missing production acceptance criteria

The tested native historical subset is implemented. Active service/domain/unpaid-invoice/open-ticket isolation, active configurable options, complete contact/custom-field equivalence, ambiguous currency/package/gateway mapping, large aggregate staging, historical legal/FX semantics and arbitrary allocation handling remain incomplete.

Native historical invoices carry an explicit non-reissue/legal-snapshot warning. Source invoice notes remain private when their visibility cannot be established; current destination company information is not claimed to be an original legal seller snapshot.

Financial assessment now compares frozen extracted facts against native data by currency and identifies missing records, monetary differences, drift and currency conflicts. Outstanding debt/allocation reconstruction and live-source cutover reconciliation remain **not verified**. Selective updates, attachments, authenticated real-admin end-to-end verification and production-scale testing remain pending.

This matrix is intentionally not a claim that all WHMCS 8.x schema variants have been tested. It reflects the local destination inspection and synthetic 8.x-shaped fixtures.
