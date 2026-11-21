# Source connection setup — 0.2

All settings below affect only the separate WHMCS source connection. They do not
change FOSSBilling's database connection, global TLS, WHMCS settings or source
records. Credentials remain encrypted in extension-owned storage.

## Connection security

| Mode | Encryption | Server identity | Administrator action |
|---|---|---|---|
| Standard MySQL Connection (No TLS) (default) | Not requested | **Not authenticated** | Explicitly acknowledge unencrypted-transport risk |
| Verified TLS | Required | Certificate chain and hostname/IP verified | Use default CA or a provider-specific local CA file |
| TLS Without Certificate Verification (Advanced) | Required and checked | **Not authenticated** | Explicitly select and acknowledge impersonation/data-exposure risk |

For the default standard mode, enter the ordinary host/IP, port, database,
SELECT-only user, password and table prefix. No CA file or TLS-specific hostname
is required.
The certificate field is hidden/disabled outside verified mode. The public CA
bundle is used internally to trigger mysqlnd's TLS negotiation in unverified
mode, with verification explicitly disabled; the user need not supply a CA.
This is **not** equivalent to disabling TLS.

No secure failure triggers another attempt in a weaker mode. TLS modes first
inspect the MySQL greeting and complete a credential-free TLS handshake. A server
that does not advertise/negotiate TLS is refused before credentials are sent.
The PDO connection independently uses the same selected policy, and its actual
`Ssl_cipher` is checked before extraction. Grant verification and an explicit
read-only transaction remain mandatory in every mode.

An administrator can change modes and test again after accepting the displayed
warning. New run plans snapshot the mode; a changed mode blocks an existing run
until a new reviewed plan is created. Legacy 0.1 local non-TLS profiles remain
compatible; old remote plans cannot silently acquire a weaker mode.

## HostGator shared hosting / local Laragon

1. In the migrator, request **Show public IP** and allowlist the appropriate
   outbound IP in the hosting provider's Remote MySQL controls. VPN/proxy routing
   can change which IP MySQL sees.
2. Enter the remote MySQL host/IP and port (usually 3306), full account-prefixed
   database name and SELECT-only username, password and WHMCS table prefix.
3. Keep the default **Standard MySQL Connection (No TLS)** when the provider's
   certificate cannot be verified, acknowledge the warning and test. No
   certificate fields are needed. Prefer an independently secured tunnel rather
   than exposing customer data over a public plaintext connection.
4. If you can validate the provider's hostname/CA, choose **Verified TLS**.
5. If encryption is available but the certificate cannot be authenticated and you
   explicitly accept the impersonation risk, choose **TLS Without Certificate
   Verification (Advanced)** and acknowledge the warning. It must still negotiate
   real encryption.
6. Keep WHMCS version on **Auto-detect** and confirm the **WHMCS server timezone**
   dropdown (default **America/Chicago**). Click **Test connection**.
7. Review actual encryption, server identity, database/table availability and the
   selected timezone before creating any migration run. Test connection writes
   only extension-owned profile metadata, never destination business records.

Correct credentials do not guarantee network access: remote grants, firewall
rules, account-host restrictions and server TLS policy can independently refuse
a connection. The extension cannot override the hosting provider's policy.

## Diagnostics

Fixed, credential-free messages distinguish DNS resolution, known network-route
failure, refused port, timeout, authentication, missing database/SELECT access,
certificate validation, TLS negotiation and connection interruption.

A timed-out TCP connection does **not** prove whether a firewall blocked the port
or the host was unreachable. That uncertainty is disclosed rather than guessed.
Raw driver warnings, passwords and SQL parameters are not returned or logged by
the extension. PDO/mysqli client/version and platform behavior can vary; only the
tested PHP/mysqlnd/MySQL fixture combination is established.

## Privilege inspection and cPanel grants

The source reader prefers `SHOW GRANTS FOR CURRENT_USER()`, inspecting the actual
authenticated account (including its host-specific entry), not reconstructing an
account name from the submitted username. Unsupported syntax alone permits a
fallback to `SHOW GRANTS FOR CURRENT_USER`, then `SHOW GRANTS`. Denied inspection
does not trigger a bypass or an assertion that SELECT is missing.

Database grants such as ``GRANT SELECT ON `account\_whmcs`.*`` are parsed as SQL
identifiers/database patterns, not compared by simply removing backticks.
Backtick doubling, identifier quoting, host/account suffixes, MariaDB
authentication/resource clauses and escaped wildcard characters are handled
separately. Only sanitized scope/match descriptions appear in diagnostics; raw
grants can contain authentication hashes and must not be displayed or logged.

Public read-only server-variable metadata establishes identifier case rules and
MySQL `partial_revokes` semantics. With partial revokes enabled, stored schema
grant names are literal, including backslashes from old escaped grants. The
validator does not blindly strip those escapes. If that metadata is unavailable,
it accepts only coverage that is unambiguous under the applicable rules; otherwise
the result is **Unable to verify privileges**, not a guessed missing grant.
MariaDB/older MySQL versions without partial revokes use pattern semantics.

Success reports **database-wide SELECT**, the detected privilege scopes and
whether coverage used a literal identifier, an escaped identifier or a known
database pattern. Parsed output showing only table/column SELECT is
**Insufficient privileges**. A full database SELECT may coexist with supplemental
table/column SELECT grants, but write/administrative/delegation privileges anywhere,
global SELECT, unresolved roles and restrictions remain blocked. No global MySQL
privilege or access to `mysql.user` is required, and no GRANT/REVOKE/SET or
write-permission probe is ever issued by the source reader.

## WHMCS server timezone

The wizard shows a required IANA timezone dropdown with **America/Chicago**
selected by default. It is submitted with **Test connection** and stored only in
the extension's encrypted source profile. There is no automatic timezone
inspection: the extension does not query application timezone candidates, MySQL
session/server zones, `NOW()`/`UTC_TIMESTAMP()` offsets or remote PHP settings.
No UTC, FOSSBilling host timezone or offset-to-IANA inference is used.

The administrator must confirm the dropdown matches the WHMCS/PHP application
timezone. Clearing it leaves the source **Unverified** and blocks timezone-sensitive
native authorization. An exact IANA identifier is required because it carries DST
history; a numeric offset is refused. Existing run snapshots remain unchanged when
a saved profile's timezone is later changed.

Native timestamp-sensitive authorizations are blocked while the selection is
empty. Existing date-only renewal/registration/due dates do not shift. DATETIME
client registrations, payments, invoices, services and support events use the
selected source timezone; ambiguous/nonexistent DST times fail instead of guessing
an offset. Domains remain archive-only under the existing operational-safety
restriction. Historical timezone changes still require manual period-by-period
review; today's setting is not proof of every historical timestamp's interpretation.

An actual MySQL `TIMESTAMP` column is session-converted on read and is not
equivalent to an application-local `DATETIME`. Native planning refuses reviewed
date/event fields that use this storage type rather than applying a potentially
wrong application timezone; archive mode remains available. A storage-specific
conversion adapter is required for such nonstandard schemas.
