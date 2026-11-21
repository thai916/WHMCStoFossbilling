/* UI harness uses synthetic API responses; it does NOT test a live authenticated admin session. */
const path = require("node:path");
const fs = require("node:fs");
const http = require("node:http");
const { spawnSync } = require("node:child_process");
const assert = require("node:assert/strict");
const moduleDir = path.resolve(__dirname, "..");
const rootDir = path.resolve(moduleDir, "../..");
const artifacts = path.join(moduleDir, ".local", "browser-artifacts");
fs.mkdirSync(artifacts, { recursive: true });
process.env.TEMP = artifacts;
process.env.TMP = artifacts;
const { chromium } = require(process.env.WM_PLAYWRIGHT_PATH || path.join(moduleDir, ".local/browser/node_modules/playwright"));
const php = process.env.WM_PHP || "php";
const rendered = spawnSync(php, [path.join(__dirname, "render.php"), "--stdout"], { encoding: "utf8" });
assert.equal(rendered.status, 0, "Twig render succeeds");
const content = {
    "/": [rendered.stdout, "text/html"],
    "/wizard.js": [fs.readFileSync(path.join(moduleDir, "assets/wizard.js")), "application/javascript"],
    "/wizard.css": [fs.readFileSync(path.join(moduleDir, "assets/wizard.css")), "text/css"],
    "/icon.png": [fs.readFileSync(path.join(moduleDir, "icon.png")), "image/png"],
    "/vendor.css": [fs.readFileSync(path.join(rootDir, "themes/admin_default/assets/build/css/vendor.css")), "text/css"],
    "/fossbilling.css": [fs.readFileSync(path.join(rootDir, "themes/admin_default/assets/build/css/fossbilling.css")), "text/css"],
};
const schema = {
    tblclients: { suffix: "clients", key: "id", classification: "archive", columns: { id: { type: "int", sensitive: false }, email: { type: "varchar(255)", sensitive: false }, password: { type: "varchar(255)", sensitive: true } } },
    tblconfiguration: { suffix: "configuration", key: "id", classification: "excluded_sensitive", columns: { value: { type: "text", sensitive: false } } }
};
const profile = { version: 1, fields: { tblclients: { id: { action: "archive", target: null, transform: "identity", reason: "" }, email: { action: "native_and_archive", target: "email", transform: "identity", reason: "" }, password: { action: "exclude_sensitive", target: null, transform: "identity", reason: "" } }, tblconfiguration: { value: { action: "exclude_sensitive", target: null, transform: "identity", reason: "" } } } };
// A genuinely long list catches layout and scrolling regressions hidden by a one-row fixture.
for (const suffix of ["currencies", ...Array.from({ length: 40 }, (_, i) => `history_${i}_long_table_name`)]) {
    const name = `tbl${suffix}`;
    schema[name] = { suffix, key: "id", classification: "archive", columns: { id: { type: "int", sensitive: false } } };
    profile.fields[name] = { id: { action: "archive", target: null, transform: "identity", reason: "" } };
}
const source = {
    source_id: 1, label: "synthetic-fixture", version: "8.10.1", schema, profile, native_client_targets: ["email", "first_name"],
    analysis: [], blockers: ["Native operational imports are blocked."],
    fields: Object.entries(schema).flatMap(([table, def]) => Object.entries(def.columns).map(([field, col]) => ({ category: "Synthetic fixture", table, field, type: col.type, required: field === "id", classification: def.classification, ...profile.fields[table][field] })))
};
let currentRun = null;
let failNextCreation = false, failNextAnalysis = false;
const analyzedTables = new Set();
let schemaUpgradeRequired = true;
let ipLookups = 0;
const historicalRunId = "b".repeat(32);
const historicalRun = {
    id: historicalRunId, mode: "historical_native", status: "requires_action", last_batch_at: "2026-01-01 00:00:00",
    categories: [{ table_name: "tblclients", expected: 3, processed: 3, imported: 3, archived: 0, skipped: 0, failed: 0 }],
    errors: [],
    reconciliation: {
        status: "assessed", reason: "Extracted subset only; not live cutover reconciliation.",
        totals_by_currency: {
            closing_credit: [{ currency: "USD", source_total: "30.00", native_total: "25.00", source_records: 3, native_records: 2 }],
            invoice_value: [{ currency: "EUR", source_total: "22.00", native_total: null, source_records: 1, native_records: 0 }]
        }
    },
    credit_publication: [{ source_key: "3", status: "failed", message: "Closing balance mismatch <script>window.XSS_EXECUTED=true</script>" }]
};
const requests = [];
const testedModes = [];
const batchRequestIds = [];
const server = http.createServer((request, response) => {
    const item = content[request.url];
    if (!item) { response.writeHead(404); response.end(); return; }
    response.writeHead(200, { "Content-Type": item[1] }); response.end(item[0]);
});
(async () => {
    await new Promise(resolve => server.listen(0, "127.0.0.1", resolve));
    const port = server.address().port;
    const browser = await chromium.launchPersistentContext(path.join(artifacts, "profile"), {
        headless: true, executablePath: process.env.WM_CHROME || "C:/Program Files/Google/Chrome/Application/chrome.exe",
        args: ["--disable-breakpad", "--disable-crash-reporter", "--disable-background-networking", `--crash-dumps-dir=${artifacts}`],
        viewport: { width: 1365, height: 1000 },
    });
    try {
        const page = await browser.newPage(), errors = [];
        page.on("pageerror", error => errors.push(error.message));
        await page.route("**/api/admin/whmcstofossbilling/**", async route => {
            const request = route.request(), method = request.url().split("/").pop(), data = request.postDataJSON();
            requests.push(method);
            assert.equal(request.method(), "POST");
            assert.equal(request.headers()["x-csrf-token"], "test-session-csrf");
            let result;
            switch (method) {
                case "dashboard": result = { schema_upgrade_required: schemaUpgradeRequired, environment: { blockers: source.blockers }, sources: [{ id: 1, label: source.label, tested_at: "2026-01-01" }], runs: [{ id: historicalRunId, created_at: "2026-01-01", mode: "historical_native", status: "requires_action", label: "synthetic history" }] }; break;
                case "schema_upgrade":
                    assert.equal(data.confirmation, "UPGRADE EXTENSION TABLES");
                    schemaUpgradeRequired = false; result = { upgraded: true }; break;
                case "test_connection":
                    assert.equal(data.password, "synthetic-only");
                    testedModes.push(data.security_mode);
                    if (data.security_mode === "verified_tls") assert.ok(data.tls_ca.length > 0);
                    else {
                        assert.equal(data.tls_ca, undefined, "Non-verified modes do not submit hidden CA inputs");
                        assert.equal(data.security_acknowledged, true);
                    }
                    assert.equal(data.source_timezone, "America/Chicago", "Selected WHMCS timezone is submitted with the connection test");
                    assert.equal(data.source_timezone_verified, "1", "Dropdown selection is submitted as an explicit administrator selection");
                    assert.equal(data.confirm_whmcs8, "", "Auto-detect is the default version policy");
                    source.connection_status = { successful: true, security_mode: data.security_mode, tls_active: data.security_mode !== "standard", server_identity_verified: data.security_mode === "verified_tls", database: "synthetic", table_availability: { available: true, tables_found: 6 }, privileges: { summary: "Database-wide SELECT verified; no non-SELECT privileges or delegation detected.", detected_scopes: ["database SELECT"], matched_by: ["escaped database identifier"] } };
                    source.source_timezone = data.source_timezone;
                    source.timezone_detection = { status: "verified", timezone: data.source_timezone, detected_timezone: null, method: "administrator_selected_application_timezone", confidence: "administrator_verified", automatic_inconclusive: false, warning: "Saved only in this extension profile." };
                    result = source; break;
                case "public_ip":
                    assert.deepEqual(data, { confirmation: "LOOK UP PUBLIC IP" }, "IP lookup sends no database credentials");
                    if (++ipLookups === 2) {
                        await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ result: null, error: { message: "Synthetic lookup unavailable" } }) });
                        return;
                    }
                    result = { ip: "8.8.8.8", provider: "https://api.ipify.org", checked_at: "2026-01-01 00:00:00" }; break;
                case "mapping_get": result = source; break;
                case "mapping_save": result = { profile: data.profile, validated: true }; break;
                case "analysis_prepare": {
                    const tables = [...new Set([...data.tables, ...(data.target_mode !== "archive" && data.tables.includes("tblclients") ? ["tblcurrencies"] : [])])];
                    result = { tables, issues: Object.fromEntries(tables.filter(name => !analyzedTables.has(name)).map(name => [name, "missing"])) };
                    break;
                }
                case "analyze_table":
                    if (failNextAnalysis) {
                        failNextAnalysis = false;
                        await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ error: { message: `The source schema changed while analyzing ${data.table}. Test the connection again.` } }) });
                        return;
                    }
                    analyzedTables.add(data.table);
                    result = { table: data.table, count: 1, maximum: "1" }; break;
                case "run_create":
                    if (failNextCreation) {
                        failNextCreation = false;
                        await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ error: { message: "Source counts need refreshing: tblclients (stale), tblcurrencies (missing). Analyze these tables again." } }) });
                        return;
                    }
                    if (data.mode === "dry_run") {
                        assert.equal(data.target_mode, "historical_native", "Preview keeps the explicitly selected target mode");
                        assert.equal(data.invalid_date_fallback, "1900", "Explicit invalid-date policy is submitted with the run plan");
                    } else {
                        assert.equal(data.mode, "archive", "Authorized run uses a synthetic API only; no live writes");
                        assert.equal(data.confirmation, "PRESERVE HISTORY");
                    }
                    currentRun = { id: "a".repeat(32), mode: data.mode, status: "paused", last_batch_at: null, categories: [{ table_name: "tblclients", expected: 1, processed: 0, imported: 0, archived: 0, skipped: 0, failed: 0 }], errors: [], reconciliation: { reason: "Not performed" } };
                    result = currentRun; break;
                case "run_control": currentRun.status = data.action === "resume" ? "running" : "paused"; result = currentRun; break;
                case "run_batch":
                    assert.match(data.request_id, /^[a-f0-9]{32}$/, "Batch idempotency key stays 32 lowercase hex characters without randomUUID");
                    batchRequestIds.push(data.request_id);
                    currentRun.status = "preview_finished"; currentRun.categories[0].processed = 1; currentRun.categories[0].archived = 1; result = { status: currentRun.status }; break;
                case "run_status": result = data.run_id === historicalRunId ? historicalRun : currentRun; break;
                case "archive_search":
                    assert.equal(data.legacy_kind, "invoice");
                    assert.equal(data.legacy_reference, "LEGACY-001");
                    result = { records: [{ table_name: "tblnotes", source_key: "1", payload: { note: "<script>window.XSS_EXECUTED=true</script>" } }], next_offset: null }; break;
                default: throw new Error("Unexpected UI request " + method);
            }
            await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ result, error: null }) });
        });
        await page.addInitScript(() => {
            // Test clipboard wiring without changing the user's real clipboard.
            Object.defineProperty(navigator, "clipboard", { value: { writeText: async text => { window.TEST_COPIED_IP = text; } } });
            // Reproduce non-secure origins such as http://fossbill.test where randomUUID is unavailable.
            if (globalThis.Crypto?.prototype) Object.defineProperty(Crypto.prototype, "randomUUID", { value: undefined, configurable: true });
        });
        await page.goto(`http://127.0.0.1:${port}/`);
        await page.waitForLoadState("networkidle");
        assert.equal(await page.locator("#wm-next").isDisabled(), true);
        assert.equal(requests.includes("test_connection"), false, "No connection runs on page load");
        assert.equal(requests.includes("public_ip"), false, "No external IP lookup runs on page load");
        assert.equal(await page.locator("#wm-timezone").inputValue(), "America/Chicago", "WHMCS timezone dropdown defaults to America/Chicago without automatic inspection");
        assert.equal(await page.locator("#wm-security-mode").inputValue(), "standard", "Standard no-TLS connection is the default");
        assert.equal(await page.locator("#wm-version").inputValue(), "");
        assert.ok((await page.locator("#wm-ca").inputValue()).length > 0);
        assert.equal(await page.locator(".wm-logo").evaluate(img => img.complete && img.naturalWidth > 0), true, "Supplied PNG loads");
        assert.equal(await page.locator("#wm-schema-warning").isVisible(), true);
        page.once("dialog", dialog => dialog.dismiss());
        await page.locator("#wm-schema-upgrade").click();
        assert.equal(requests.includes("schema_upgrade"), false, "Declined schema confirmation makes no API call");
        page.once("dialog", dialog => dialog.accept());
        await page.locator("#wm-schema-upgrade").click();
        await page.waitForFunction(() => document.getElementById("wm-schema-warning").classList.contains("d-none"));
        assert.equal(requests.filter(method => method === "schema_upgrade").length, 1);
        assert.equal(requests.includes("test_connection"), false, "Schema upgrade never connects to a source");
        await page.locator("#wm-ip-lookup").click();
        await page.waitForFunction(() => !document.getElementById("wm-ip-copy").disabled);
        assert.equal(await page.locator("#wm-public-ip").innerText(), "8.8.8.8");
        await page.locator("#wm-ip-copy").click();
        await page.waitForFunction(() => window.TEST_COPIED_IP === "8.8.8.8");
        await page.locator("#wm-ip-lookup").click();
        await page.getByText("Unavailable", { exact: true }).waitFor();
        assert.equal(await page.locator("#wm-ip-copy").isDisabled(), true, "Failed refresh never leaves an outdated IP copyable");
        await page.locator("#wm-host").fill("127.0.0.1");
        await page.locator("#wm-database").fill("synthetic");
        await page.locator("#wm-username").fill("reader");
        for (const mode of ["standard", "verified_tls", "unverified_tls"]) {
            await page.locator("#wm-security-mode").selectOption(mode);
            await page.locator("#wm-password").fill("synthetic-only");
            assert.equal(await page.locator("#wm-tls-settings").isVisible(), mode === "verified_tls");
            if (mode !== "verified_tls") {
                const before = testedModes.length;
                await page.getByRole("button", { name: "Test connection", exact: true }).click();
                assert.equal(testedModes.length, before, "Weaker modes cannot be submitted without explicit confirmation");
                await page.locator("#wm-security-acknowledged").check();
            }
            await page.getByRole("button", { name: "Test connection", exact: true }).click();
            await page.waitForFunction(() => document.getElementById("wm-password").value === "");
            assert.match(await page.locator("#wm-source-status").innerText(), /Connection successful/);
            assert.equal(source.connection_status.security_mode, mode);
        }
        assert.deepEqual(testedModes, ["standard", "verified_tls", "unverified_tls"]);
        assert.match(await page.locator("#wm-source-status").innerText(), /Database-wide SELECT verified/);
        assert.match(await page.locator("#wm-source-status").innerText(), /escaped database identifier/);
        assert.match(await page.locator("#wm-source-status").innerText(), /WHMCS server timezone/);
        assert.match(await page.locator("#wm-source-status").innerText(), /America\/Chicago/);
        assert.equal(await page.locator("#wm-timezone").inputValue(), "America/Chicago", "Selected timezone remains in the dropdown without any inference");
        assert.equal(source.source_timezone, "America/Chicago");
        assert.equal(await page.locator("#wm-source-status h4").getAttribute("class"), "wm-success", "Successful connection message uses the dark green class");
        assert.equal(await page.locator("#wm-source-status h4").evaluate(el => getComputedStyle(el).color), "rgb(11, 93, 42)", "Successful connection message renders in dark green");
        assert.equal(requests.includes("timezone_confirm"), false, "Timezone is saved with the connection test; no separate override request exists");
        await page.locator("#wm-next").click();
        await page.locator("#wm-field-search").fill("password");
        assert.match(await page.locator("#wm-fields").innerText(), /Excluded sensitive field/);
        await page.locator("#wm-field-search").fill("");
        await page.locator("#wm-review").click();
        await page.locator("#wm-analyze").click();
        await page.getByText("Selected table counts captured, including required dependencies.", { exact: false }).waitFor();
        assert.equal(await page.locator('#wm-tables input[value="tblconfiguration"]').isDisabled(), true);
        assert.equal(await page.locator("#wm-tables").evaluate(el => getComputedStyle(el).gridTemplateColumns.split(" ").length), 3, "Desktop table list uses three columns");
        const selectedBeforeSearch = await page.locator("#wm-tables input:checked").count();
        await page.locator("#wm-table-search").fill("tblclients");
        assert.equal(await page.locator(".wm-table-row:visible").count(), 1);
        assert.equal(await page.locator("#wm-tables input:checked").count(), selectedBeforeSearch, "Filtering never changes preservation selection");
        await page.locator("#wm-table-search").fill("not-a-table");
        assert.equal(await page.locator("#wm-table-empty").isVisible(), true);
        await page.locator("#wm-table-search").fill("");
        await page.setViewportSize({ width: 900, height: 844 });
        assert.equal(await page.locator("#wm-tables").evaluate(el => getComputedStyle(el).gridTemplateColumns.split(" ").length), 2, "Tablet table list uses two columns");
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.locator("#wm-tables").evaluate(el => getComputedStyle(el).gridTemplateColumns.split(" ").length), 1, "Mobile table list uses one column");
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false, "Long mobile table names do not overflow");
        await page.screenshot({ path: path.join(artifacts, "mobile-review.png"), fullPage: true });
        await page.setViewportSize({ width: 1365, height: 1000 });
        await page.screenshot({ path: path.join(artifacts, "desktop-review.png"), fullPage: true });
        // Only select clients; native dependency counts must be refreshed automatically.
        for (const checkbox of await page.locator("#wm-tables input:not(:disabled)").all()) await checkbox.uncheck();
        await page.locator('#wm-tables input[value="tblclients"]').check();
        analyzedTables.delete("tblcurrencies");
        await page.locator("#wm-mode").selectOption("historical_native");
        await page.locator("#wm-invalid-date-fallback").check();
        await page.locator("#wm-dry-run").click();
        assert.equal(requests.includes("run_batch"), false, "Preview creation cannot start scheduling before explicit Resume");
        await page.locator("#wm-top-progress").waitFor({ state: "visible" });
        await page.waitForFunction(() => !document.getElementById("wm-dry-run").disabled && window.scrollY === 0);
        assert.equal(await page.evaluate(() => window.scrollY), 0, "Dry-run creation scrolls to top");
        assert.match(await page.locator("#wm-notice").innerText(), /counts refreshed for: tblcurrencies/);
        assert.equal(await page.locator('#wm-tables input[value="tblcurrencies"]').isChecked(), true, "Expanded dependencies remain selected for review");
        assert.equal(await page.locator("#wm-top-progress").isVisible(), true, "Top progress bar appears as soon as a run exists");
        assert.equal(await page.locator("#wm-progress-percent").innerText(), "0%", "A newly created run starts at zero percent");
        assert.match(await page.locator("#wm-progress-label").innerText(), /dry_run · paused · 0\/1 records scanned/);
        assert.equal(await page.locator("#wm-top-progress [role=progressbar]").getAttribute("aria-valuenow"), "0");
        await page.locator("#wm-resume").click();
        await page.waitForFunction(() => document.getElementById("wm-run-summary").textContent.includes("preview_finished"));
        assert.ok(batchRequestIds.length >= 1, "Resume schedules at least one batch when randomUUID is unavailable");
        assert.equal(new Set(batchRequestIds).size, batchRequestIds.length, "Fallback batch ids remain unique");
        assert.equal(await page.locator("#wm-progress-percent").innerText(), "100%", "Top progress bar reaches 100% after all expected records are scanned");
        assert.equal(await page.locator("#wm-progress-bar").evaluate(el => el.style.width), "100%");
        assert.equal(await page.locator("#wm-top-progress [role=progressbar]").getAttribute("aria-valuenow"), "100");
        assert.match(await page.locator("#wm-progress-label").innerText(), /preview_finished · 1\/1 records scanned/);
        assert.match(await page.locator("#wm-date-fallbacks").innerText(), /No invalid date was substituted/);
        assert.equal(await page.locator("#wm-archive-total").innerText(), "Not saved", "Preview counts never claim history was saved");
        await page.locator('[data-step="analysis"]').click();
        await page.locator("#wm-mode").selectOption("archive");
        await page.locator("#wm-backup").fill("Synthetic browser fixture — not a real authorization");
        await page.locator('[name="backup_verified"]').check();
        await page.locator('[name="accept_limitations"]').check();
        await page.locator("#wm-confirm").fill("PRESERVE HISTORY");
        const batchesBeforeAuthorization = requests.filter(name => name === "run_batch").length;
        const authorize = page.getByRole("button", { name: "Create authorized run (starts paused)", exact: true });
        failNextAnalysis = true;
        analyzedTables.delete("tblclients");
        await authorize.click();
        await page.getByText("Counts still need refreshing for: tblclients", { exact: false }).waitFor();
        await page.waitForFunction(() => window.scrollY === 0);
        assert.match(await page.locator("#wm-notice").innerText(), /schema changed.*tblclients/);
        assert.equal(await page.evaluate(() => window.scrollY), 0, "Schema-change failure scrolls to top");
        failNextCreation = true;
        await authorize.click();
        await page.getByText("tblclients (stale), tblcurrencies (missing)", { exact: false }).waitFor();
        await page.waitForFunction(() => window.scrollY === 0);
        assert.equal(await page.evaluate(() => window.scrollY), 0, "Authorized-run failure scrolls to top");
        await authorize.click();
        await page.waitForFunction(() => document.getElementById("wm-progress-label").textContent.includes("archive · paused"));
        await page.waitForFunction(() => window.scrollY === 0);
        assert.equal(await page.evaluate(() => window.scrollY), 0, "Authorized-run success scrolls to top");
        assert.equal(requests.filter(name => name === "run_batch").length, batchesBeforeAuthorization, "Authorized creation remains paused without scheduling batches");
        assert.equal(await page.locator("#wm-top-progress").evaluate(el => { const box = el.getBoundingClientRect(); return box.top >= 0 && box.bottom <= window.innerHeight; }), true, "Authorized-run progress is in the viewport after creation");
        await page.screenshot({ path: path.join(artifacts, "preservation-progress.png"), fullPage: true });
        currentRun.categories[0].expected = 0;
        await page.locator("#wm-refresh").click();
        await page.waitForFunction(() => document.getElementById("wm-progress-label").textContent.includes("0/0 records scanned"));
        assert.equal(await page.locator("#wm-progress-percent").innerText(), "0%", "An empty source stays at zero percent");
        await page.locator('[data-step="archive"]').click();
        await page.locator('[name="legacy_reference"]').fill("LEGACY-001");
        await page.locator("#wm-archive-search button").click();
        await page.locator("#wm-archive-results details").waitFor();
        assert.equal(await page.evaluate(() => window.XSS_EXECUTED), undefined, "Archive HTML never executes");
        assert.equal(await page.evaluate(() => localStorage.length), 0, "No browser storage used");
        assert.equal(await page.evaluate(() => sessionStorage.length), 0, "No session storage used");
        await page.locator('[data-step="progress"]').click();
        await page.locator("#wm-runs").selectOption(historicalRunId);
        await page.waitForFunction(() => document.getElementById("wm-run-summary").textContent.includes("requires_action"));
        assert.equal(await page.locator("#wm-progress-percent").innerText(), "100%", "Top progress follows the selected run");
        assert.match(await page.locator("#wm-progress-label").innerText(), /requires_action · 3\/3 records scanned/);
        const financialRows = await page.locator("#wm-financial tbody tr").allTextContents();
        assert.ok(financialRows.some(row => row.includes("USD") && row.includes("30.00") && row.includes("25.00")), "Source/native credit discrepancy remains visible");
        assert.ok(financialRows.some(row => row.includes("EUR") && row.includes("Not represented")), "Missing native value is not rendered as zero");
        assert.match(await page.locator("#wm-credit-results").innerText(), /Closing balance mismatch/);
        assert.equal(await page.evaluate(() => window.XSS_EXECUTED), undefined, "Financial diagnostics never execute HTML");
        await page.locator('[data-step="connection"]').click();
        await page.screenshot({ path: path.join(artifacts, "desktop.png"), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(artifacts, "mobile.png"), fullPage: true });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false, "Mobile connection page has no horizontal overflow");
        assert.deepEqual(errors, [], "No browser JavaScript errors");
        console.log("PASS: synthetic browser wizard, connection modes/timezone/security controls, 43-table responsive 3/2/1-column review, selection-safe search, automatic dependency analysis, schema-change and stale-count diagnostics, successful/failed authorized creation scroll and visible progress without batch scheduling, preservation/preview summaries, zero-expected progress, opt-in invalid-date policy, preview/resume with missing-randomUUID fallback, legacy search, financial displays, XSS protection and no browser storage");
        const livePage = await browser.newPage();
        await livePage.goto("http://fossbill.test/admin/whmcstofossbilling");
        await livePage.waitForLoadState("networkidle");
        assert.match(await livePage.title(), /Login/i);
        console.log("PASS: unauthenticated real local admin page requires login");
    } finally {
        await browser.close();
        server.close();
    }
})().catch(error => { console.error(error.message); server.close(); process.exitCode = 1; });
