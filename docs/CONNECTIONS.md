# Source connection setup — 0.2

All settings below affect only the separate WHMCS source connection. They do not
change FOSSBilling's database connection, global TLS, WHMCS settings or source
records. Credentials remain encrypted in extension-owned storage.

## Connection security

| Mode | Encryption | Server identity | Administrator action |
|---|---|---|---|
| Verified TLS (default) | Required | Certificate chain and hostname/IP verified | Use default CA or a provider-specific local CA file |
| TLS Without Certificate Verification (Advanced) | Required and checked | **Not authenticated** | Explicitly select and acknowledge impersonation/data-exposure risk |
| Standard MySQL Connection (No TLS) | Not requested | **Not authenticated** | Explicitly select and acknowledge unencrypted-transport risk |

For standard mode, enter the ordinary host/IP, port, database, SELECT-only user,
password and table prefix. No CA file or TLS-specific hostname is required.
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
3. Prefer **Verified TLS** with the provider's correct hostname/CA.
4. If the provider's certificate is not verifiable and you explicitly accept the
   impersonation risk, choose **TLS Without Certificate Verification (Advanced)**,
   acknowledge the warning and test. It must still negotiate real encryption.
5. If the server does not support usable TLS and you accept plaintext exposure,
   explicitly choose **Standard MySQL Connection (No TLS)** and acknowledge its
   warning. No certificate fields are needed. Prefer an independently secured
   tunnel rather than exposing customer data over a public plaintext connection.
6. Keep WHMCS version on **Auto-detect**. Click **Test connection**.
7. Review actual encryption, server identity, database/table availability and
   timezone evidence before creating any migration run. Test connection writes
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

## Automatic timezone evidence

After successful source access, the extension reads only bounded, allowlisted
timezone-named configuration candidates and:

```sql
SELECT @@session.time_zone, @@global.time_zone, NOW(), UTC_TIMESTAMP()
```

The actual query also computes the observed session UTC offset with
`TIMESTAMPDIFF`. No `SET`, `SET GLOBAL`, remote file read or WHMCS API call is used.

The wizard displays:

- Exact application configuration candidate(s), if present.
- Database session/server timezones and observed **session** offset.
- Detection method, confidence and Verified/Unverified status.
- The explicit limitation that remote PHP runtime timezone is unavailable via DB.

**No authoritative WHMCS 8.x PHP runtime timezone can be proven solely from these
database observations.** A timezone-like configuration row may be unused or
overridden by `configuration.php`, PHP settings or hooks. It is therefore a
candidate, not automatically verified application evidence. Missing/conflicting
candidates also remain Unverified. An offset never becomes a guessed IANA zone,
and the FOSSBilling host timezone/UTC is never substituted.

When automatic evidence is inconclusive, the optional override can confirm a
detected IANA candidate or select the independently verified WHMCS application
timezone. It updates only the encrypted extension profile, without another
password submission. The method then explicitly says administrator-verified;
database metadata never outranks that verification. Existing run snapshots remain
unchanged.

Native timestamp-sensitive authorizations are blocked while unresolved. Existing
date-only renewal/registration/due dates do not shift. DATETIME client registrations,
payments, invoices, services and support events use the verified source timezone;
ambiguous/nonexistent DST times fail instead of guessing an offset. Domains remain
archive-only under the existing operational-safety restriction. Historical timezone
changes still require manual period-by-period review; today's setting is not proof
of every historical timestamp's interpretation.

An actual MySQL `TIMESTAMP` column is session-converted on read and is not
equivalent to an application-local `DATETIME`. Native planning refuses reviewed
date/event fields that use this storage type rather than applying a potentially
wrong application timezone; archive mode remains available. A storage-specific
conversion adapter is required for such nonstandard schemas.
