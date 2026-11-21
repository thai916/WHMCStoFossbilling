# Created source files

All paths below are relative to `modules/Whmcsmigrator/`. No existing FOSSBilling source file was edited.

```text
.gitignore
.gitattributes
.htaccess
LICENSE
README.md
manifest.json
icon.png
Service.php
Api/Admin.php
Archive/Repository.php
Archive/Sanitizer.php
Controller/Admin.php
Destination/NativeWriter.php
Destination/Store.php
Destination/Persistence.php
Destination/ProductImporter.php
Destination/ProductPricing.php
Destination/ServerImporter.php
Destination/HostingImporter.php
Destination/InvoiceImporter.php
Destination/PaymentImporter.php
Destination/CreditPublisher.php
Destination/HistoryImporter.php
Finance/Decimal.php
Finance/Reconciler.php
Installation/Schema.php
Mapping/Profile.php
Mapping/EventDate.php
Mapping/PreviewValidator.php
Migration/Engine.php
Migration/Planner.php
Migration/Dependencies.php
Report/Exporter.php
Security/SafeException.php
Security/Sensitive.php
Security/Vault.php
Source/Catalog.php
Source/ConnectionOptions.php
Source/ConnectionFactory.php
Source/ConnectionDiagnostics.php
Source/SourceTimezone.php
Source/CaBundle.php
Source/PublicIp.php
Source/GrantPolicy.php
Source/Reader.php
assets/wizard.css
assets/wizard.js
certificates/cacert.pem
certificates/LICENSE-MPL-2.0.txt
certificates/README.md
templates/admin/mod_whmcsmigrator_index.html.twig
templates/admin/mod_whmcsmigrator_settings.html.twig
tests/bootstrap.php
tests/browser.cjs
tests/integration.php
tests/connection.php
tests/integrity.php
tests/render.php
tests/security.php
tests/unit.php
docs/ARCHITECTURE.md
docs/COMPATIBILITY.md
docs/FILES.md
docs/OPERATIONS.md
docs/REMAINING-WORK.md
docs/TESTING.md
docs/RELEASE-0.1.md
docs/RELEASE-0.2.md
docs/CONNECTIONS.md
```

Private local-only artifacts beneath `.local/` include the original core SHA-256 baseline, integrity/test results, browser screenshots/profile, and development-only Playwright/npm installation/cache. They are ignored by Git, are not part of the deliverable, and must not be deployed or published.

Integration tests create and clean up only randomly named disposable local databases/accounts; the separate connection suite initializes and removes its own private fixture-server datadir. No live WHMCS login, extraction or import was used for these suites. Credential-free network/TLS diagnostics were performed separately at administrator request. Actual application browser requests can create administrator-approved FOSSBilling cache/log/session runtime state; those are not core source edits.
