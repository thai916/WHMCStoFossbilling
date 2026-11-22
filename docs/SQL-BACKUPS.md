# SQL backup import — experimental 0.2

## User workflow

1. Choose **Upload WHMCS backup** on the two-choice first screen.
2. Select one SQL, SQL.GZ/GZ, or ZIP file containing exactly one SQL member.
3. Set the actual WHMCS/PHP application timezone (the suggestion is America/Chicago).
   Expand **Backup details** only if a custom prefix or manual WHMCS 8.x
   confirmation is needed.
4. Click **Upload & prepare backup**. Upload, decompression, integrity verification
   and table parsing are separate visible stages. Only stages with measurable
   byte totals display a percentage.
5. Review mappings/exclusions, create and resume a preview, then authorize a
   reviewed migration. A prepared file is not an imported client history.

No destination backup reference is required for a fresh installation. The server
checks core business rows against migration identities before authorizing native
work. Defaults such as currencies/helpdesks are allowed. A native run from a
different source cannot duplicate existing migration-owned business rows. Archive-
only runs can preserve additional sources without creating native business rows.
Unrelated records block authorization; nothing is deleted to make the site fresh.
This check does not isolate an independently running cron or another writer.

## Supported export format

- Complete WHMCS 8.x UTF-8 MySQL table definitions and plain INSERT VALUES data.
  The same six required tables as the database reader must be present.
- Backtick identifiers, MySQL-escaped or doubled quoted strings, NULL, decimal/
  integer literals, small hexadecimal literals, complete INSERT column lists,
  multiline strings and extended multi-row INSERT statements.
- Single positive integer primary keys in increasing order per table. Gaps are
  fine; duplicates, zero/negative or out-of-order keys stop preparation rather
  than losing records. Export in primary-key order if necessary.
- CREATE primary keys and supported phpMyAdmin late ADD PRIMARY KEY metadata.
  Late column/type changes, arbitrary expressions/default-value INSERTs, routines,
  nonstandard SQL modes/encodings and unrecognized executable comments are refused.
- Standard stored/deflated ZIP and ZIP64 directory/member sizes. No password-
  protected ZIP, split ZIP, nested archives, TAR.GZ, multiple SQL members or
  concatenated GZIP streams. Archive names are never extracted to filesystem paths.
- CRC and authenticated encryption checks must pass. Corrupt, partial or
  unsupported input cannot become a migration source.

The parser is deliberately not a general SQL engine. CREATE/DROP/SET/LOCK/ALTER
backup scaffolding is consumed or validated as metadata, never executed. FOSSBilling
core table structures are never created, altered, dropped, truncated or replaced.
Activation still needs the extension's existing tracking/archive tables; this
feature adds no new SQL tables or schema patches.

## Large files, storage and performance

- Requires 64-bit PHP. zlib is required for compressed files; ZipArchive, shell
  commands, external services and Composer changes are not required.
- Application bounds: 64 GiB uploaded / 128 GiB expanded, with at least a 64 MiB
  free-space safety margin. These are ceilings, **not tested production-volume
  guarantees**. Disk, web-server request/time limits and PHP limits still apply.
- Users select one file. Authenticated internal 256 KiB blocks avoid loading the
  entire upload into PHP/browser memory or depending on upload_max_filesize for
  the whole backup. A failed block can be retried idempotently in the same page.
- Preparation checkpoints between complete INSERT tuples. Large extended INSERTs
  are not loaded as one statement. Encrypted table files use sparse key indexes
  and binary search for migration cursors.
- Expanded data and row buffers are bounded. Individual token/row/archive limits
  remain; binary/invalid UTF-8 and unsupported large-object cases are refused or
  explicitly marked oversized, never silently truncated.
- Compression is replayed from the start to the last expanded checkpoint between
  requests; this can be slower for very large compressed backups. CRCs are combined
  across output blocks without rereading the whole expanded file. Plain SQL avoids
  decompression entirely. No claim of improved million-row throughput is made
  without a representative benchmark.
- Migration still uses the existing browser-driven bounded batch engine. A
  background worker/CLI importer is not part of this change.

## Privacy, resume and cleanup

- Use HTTPS or a trusted local transport. A database backup contains private data
  and can include source credentials even though those fields are excluded from
  migration.
- Raw uploads, expanded SQL, prepared rows and checkpoints are authenticated and
  encrypted on disk under `modules/Whmcstofossbilling/.local/backups/`. No uploaded
  SQL or customer values are written to ordinary diagnostic responses/logs.
- Filesystem paths are server-generated; upload names are not used as paths.
  New upload/prepare/finalize/remove endpoints require administrator configure
  permission and a session-bound POST/CSRF token. Upload operations are scoped to
  their creating administrator.
- Sensitive fields are excluded from prepared row data, and existing private
  archive redaction/native-adapter restrictions remain in effect. Unknown/opaque
  tables still require review. SQL backups do not contain attachment file contents.
- Keep the page open to pause/continue an unfinished upload/preparation. Reloading
  loses the in-memory upload token; abandoned encrypted jobs require administrator
  filesystem cleanup after checking they are not needed. There is no automatic
  cleanup daemon or cross-reload upload recovery in this release.
- Prepared sources and runs are saved. Their plans freeze the specific backup
  identity; a different file cannot silently replace an authorized source.
- **Remove private backup** requires confirmation and refuses files needed by
  paused/running runs. Removing terminal source files does not remove imported
  records, snapshots or archives, but prevents further rereads/retries from that
  source. Retain the files until migration and review are finished.
- Preserve `.local/backups/`, its original installation path and the installation
  secret during upgrades/restoration. Development `.local/` tools are not release
  assets. Files are encrypted even if an incorrectly configured web server ignores
  `.htaccess`; still deny all direct module access except the branding icon.

## Verification boundaries

Synthetic tests cover SQL/GZIP/ZIP/ZIP64, extended INSERTs, Unicode/escaping,
late keys, upload replay, owner checks, excluded secrets, corruption/traversal/
truncation, CRC composition, sparse seeks and multi-block compressed data.
Disposable MySQL tests exercise the actual archive engine and native client
import into a separately fresh FOSSBilling-schema fixture, core schema stability,
no email queue, fresh-install blocking and protected cleanup.

No live WHMCS import, authenticated real-admin upload, real multi-gigabyte browser
upload, crash-kill recovery, arbitrary-provider dump compatibility or production
performance benchmark has been established.
